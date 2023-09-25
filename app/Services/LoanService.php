<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\ReceivedRepayment;
use App\Models\ScheduledRepayment;
use App\Models\User;
use Carbon\Carbon;

class LoanService
{
    /**
     * Create a Loan
     *
     * @param User $user
     * @param int $amount
     * @param string $currencyCode
     * @param int $terms
     * @param string $processedAt
     *
     * @return Loan
     */
    public function createLoan(User $user, int $amount, string $currencyCode, int $terms, string $processedAt): Loan
    {
        $loan = Loan::create([
            'user_id' => $user->id,
            'amount' => $amount,
            'outstanding_amount' => $amount,
            'currency_code' => $currencyCode,
            'terms' => $terms,
            'processed_at' => $processedAt,
            'status' => Loan::STATUS_DUE,
        ]);

        foreach (range(1, $terms) as $term) {
            $amountTerm = floor($amount / $terms);
            if ($term === $terms) {
                $amountTerm = $amount - ($amountTerm * ($terms - 1));
            }
            $loan->scheduledRepayments()->create([
                'amount' => $amountTerm,
                'outstanding_amount' => $amountTerm,
                'currency_code' => $currencyCode,
                'due_date' => Carbon::parse($processedAt)->addMonths($term)->format('Y-m-d'),
                'status' => Loan::STATUS_DUE,
            ]);
        }

        return $loan;
    }

    /**
     * Repay Scheduled Repayments for a Loan
     *
     * @param Loan $loan
     * @param int $amount
     * @param string $currencyCode
     * @param string $receivedAt
     *
     * @return ReceivedRepayment
     */
    public function repayLoan(Loan $loan, int $amount, string $currencyCode, string $receivedAt): ReceivedRepayment
    {
        /**
         * If all loan repayments are paid and the loan is still due, reset the outstanding amount
         */
        if ($loan->outstanding_amount == 0 && $loan->status == Loan::STATUS_DUE) {
            $this->resetOutstandingAmount($loan);
        }

        $receivedRepayment = $this->recordReceivedRepayment($loan, $amount, $currencyCode, $receivedAt);

        $scheduledRepayment = $this->findScheduledRepayment($loan, $receivedAt);

        if ($scheduledRepayment === null) {
            return $receivedRepayment;
        }

        /**
         * If this is the last repayment, mark all scheduled repayments as paid
         */
        if ($this->isLastScheduledRepayment($loan, $scheduledRepayment)) {
            $this->markAllScheduledRepaymentsAsPaid($loan);
            $this->markLoanAsPaid($loan, $scheduledRepayment);
            return $receivedRepayment;
        }

        /**
         * If the received amount matches the scheduled amount, mark the scheduled repayment as paid
         */
        if ($scheduledRepayment->amount == $receivedRepayment->amount) {
            $this->markScheduledRepaymentAsPaid($scheduledRepayment);
            $this->updateLoanStatus($loan);
            return $receivedRepayment;
        }

        /**
         * If the received amount greater than the scheduled amount, Divide the amount to the next scheduled repayment
         */
        if ($scheduledRepayment->amount < $receivedRepayment->amount) {
            $this->updateScheduledRepaymentAndCreateNext($loan, $scheduledRepayment, $receivedRepayment->amount);
            $this->updateLoanStatus($loan);
            return $receivedRepayment;
        }

        return $receivedRepayment;
    }

    /**
     * Reset outstanding amount for a Loan
     *
     * @param Loan $loan
     * @return void
     */
    private function resetOutstandingAmount(Loan $loan)
    {
        $loan->update(['outstanding_amount' => $loan->amount]);

        foreach ($loan->scheduledRepayments as $scheduledRepayment) {
            $scheduledRepayment->update(['outstanding_amount' => $scheduledRepayment->amount]);
        }
    }

    /**
     * Record received repayment
     *
     * @param Loan $loan
     * @param int $amount
     * @param string $currencyCode
     * @param string $receivedAt
     *
     * @return ReceivedRepayment
     */
    private function recordReceivedRepayment(Loan $loan, int $amount, string $currencyCode, string $receivedAt): ReceivedRepayment
    {
        return ReceivedRepayment::create([
            'loan_id' => $loan->id,
            'amount' => $amount,
            'currency_code' => $currencyCode,
            'received_at' => $receivedAt,
        ]);
    }

    /**
     * Find scheduled repayment
     *
     * @param Loan $loan
     * @param string $receivedAt
     *
     * @return ?ScheduledRepayment
     */
    private function findScheduledRepayment(Loan $loan, string $receivedAt): ?ScheduledRepayment
    {
        return ScheduledRepayment::where('loan_id', $loan->id)
            ->where('due_date', $receivedAt)
            ->first();
    }

    /**
     * Check if this is the last scheduled repayment
     *
     * @param Loan $loan
     * @param ScheduledRepayment $scheduledRepayment
     *
     * @return bool
     */
    private function isLastScheduledRepayment(Loan $loan, ScheduledRepayment $scheduledRepayment): bool
    {
        $lastScheduledRepayment = $loan->scheduledRepayments()->orderBy('id', 'desc')->first();
        return $scheduledRepayment->due_date == $lastScheduledRepayment->due_date;
    }

    /**
     * Mark all scheduled repayments as paid
     *
     * @param Loan $loan
     * @return void
     */
    private function markAllScheduledRepaymentsAsPaid(Loan $loan)
    {
        foreach ($loan->scheduledRepayments as $scheduledRepayment) {
            $this->markScheduledRepaymentAsPaid($scheduledRepayment);
        }
    }

    /**
     * Mark scheduled repayment as paid
     *
     * @param ScheduledRepayment $scheduledRepayment
     * @return void
     */
    private function markScheduledRepaymentAsPaid(ScheduledRepayment $scheduledRepayment)
    {
        $loan = $scheduledRepayment->loan;
        $loan->update([
            'outstanding_amount' => $loan->outstanding_amount - $scheduledRepayment->amount,
        ]);

        $scheduledRepayment->update([
            'status' => ScheduledRepayment::STATUS_REPAID,
            'outstanding_amount' => 0,
        ]);
    }

    /**
     * Mark loan as paid
     *
     * @param Loan $loan
     * @param ScheduledRepayment $lastScheduledRepayment
     * @return void
     */
    private function markLoanAsPaid(Loan $loan, ScheduledRepayment $lastScheduledRepayment)
    {
        $firstScheduledRepayment = $loan->scheduledRepayments()->orderBy('id')->first();
        $lastScheduledRepayment->update([
            'status' => ScheduledRepayment::STATUS_REPAID,
            'outstanding_amount' => 0,
            'due_date' => $firstScheduledRepayment->due_date,
        ]);

        $loan->update([
            'outstanding_amount' => 0,
            'status' => Loan::STATUS_REPAID,
        ]);
    }

    /**
     * Update loan status
     *
     * @param Loan $loan
     * @return void
     */
    private function updateLoanStatus(Loan $loan)
    {
        $loan = $loan->fresh();
        $loan->update([
            'status' => $loan->outstanding_amount == 0 ? Loan::STATUS_REPAID : Loan::STATUS_DUE,
        ]);
    }

    /**
     * Update scheduled repayment and create next
     *
     * @param Loan $loan
     * @param ScheduledRepayment $scheduledRepayment
     * @param int $receivedAmount
     * @return void
     */
    private function updateScheduledRepaymentAndCreateNext(Loan $loan, ScheduledRepayment $scheduledRepayment, int $receivedAmount)
    {
        $scheduledRepayment->update([
            'amount' => $scheduledRepayment->amount + 1,
            'status' => ScheduledRepayment::STATUS_REPAID,
            'outstanding_amount' => 0,
        ]);

        $remainingAmount = $receivedAmount - $scheduledRepayment->amount;

        $nextReceivedAt = Carbon::parse($scheduledRepayment->due_date)->addMonth();

        $nextScheduledRepayment = ScheduledRepayment::query()
            ->where('loan_id', $loan->id)
            ->where('due_date', $nextReceivedAt->format('Y-m-d'))
            ->first();

        $nextScheduledRepayment->update(['amount' => $scheduledRepayment->amount,
            'status' => ScheduledRepayment::STATUS_PARTIAL,
            'outstanding_amount' => $remainingAmount,
        ]);

        $outstandingAmount = $loan->outstanding_amount - $receivedAmount;

        $loan->update([
            'outstanding_amount' => $outstandingAmount,
            'status' => $outstandingAmount == 0 ? Loan::STATUS_REPAID : Loan::STATUS_DUE,
        ]);
    }
}
