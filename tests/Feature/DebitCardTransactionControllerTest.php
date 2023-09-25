<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\DebitCardTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DebitCardTransactionControllerTest extends TestCase
{
    use RefreshDatabase,WithFaker;

    /**
     * Base url for debit card transaction endpoints
     *
     * @var string
     */
    private string $baseUrl = 'api/debit-card-transactions';

    protected User $user;
    protected DebitCard $debitCard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->debitCard = DebitCard::factory()->create([
            'user_id' => $this->user->id
        ]);
        Passport::actingAs($this->user);
    }

    /**
     * Test customer can see a list of debit card transactions
     *
     * @return void
     */
    public function testCustomerCanSeeAListOfDebitCardTransactions()
    {
        $debitCard = $this->debitCard;
        DebitCardTransaction::factory()->for($debitCard)->count(10)->create();
        $response = $this->getJson($this->baseUrl . '?debit_card_id=' . $debitCard->id);
        $response->assertOk()
            ->assertJsonStructure([
                [
                     'amount', 'currency_code'
                ]
            ]);
    }

    /**
     * Test customer cannot see a list of debit card transactions of other customer debit card
     *
     * @return void
     */
    public function testCustomerCannotSeeAListOfDebitCardTransactionsOfOtherCustomerDebitCard()
    {
        $debitCard = DebitCard::factory()->create();
        DebitCardTransaction::factory()->for($debitCard)->count(10)->create();
        $response = $this->getJson($this->baseUrl . '?debit_card_id=' . $debitCard->id);
        $response->assertForbidden();
    }

    /**
     * Test customer can create a debit card transaction
     *
     * @return void
     */
    public function testCustomerCanCreateADebitCardTransaction()
    {
        $debitCard = $this->debitCard;
        $amount = $this->faker->numberBetween(1, 100);
        $currencyCode = $this->faker->randomElement(
            DebitCardTransaction::CURRENCIES,
        );
        $response = $this->postJson($this->baseUrl, [
            'debit_card_id' => $debitCard->id,
            'amount' => $amount,
            'currency_code' => $currencyCode
        ]);
        $response->assertCreated()
            ->assertJsonStructure([
                'amount', 'currency_code'
            ]);

        $this->assertDatabaseHas('debit_card_transactions', [
            'debit_card_id' => $debitCard->id,
            'amount' => $amount,
            'currency_code' =>$currencyCode
        ]);
    }

    /**
     * Test customer cannot create a debit card transaction with invalid currency code
     *
     * @return void
     */
    public function testCustomerCannotCreateADebitCardTransactionToOtherCustomerDebitCard()
    {
        $debitCard = DebitCard::factory()->create();
        $amount = $this->faker->numberBetween(1, 100);
        $currencyCode = $this->faker->randomElement(
            DebitCardTransaction::CURRENCIES,
        );
        $response = $this->postJson($this->baseUrl, [
            'debit_card_id' => $debitCard->id,
            'amount' => $amount,
            'currency_code' => $currencyCode
        ]);
        $response->assertForbidden();
    }

    /**
     * Test customer cannot create a debit card transaction with invalid currency code
     *
     * @return void
     */
    public function testCustomerCanSeeADebitCardTransaction()
    {
        $debitCardTransaction = DebitCardTransaction::factory()->for($this->debitCard)->create();
        $response = $this->getJson($this->baseUrl . '/' . $debitCardTransaction->id);
        $response->assertOk()
            ->assertJsonStructure([
                'amount', 'currency_code'
            ]);
    }

    /**
     * Test customer cannot see a single debit card transaction attached to other customer debit card
     *
     * @return void
     */
    public function testCustomerCannotSeeADebitCardTransactionAttachedToOtherCustomerDebitCard()
    {
        $debitCardTransaction = DebitCardTransaction::factory()->create();
        $response = $this->getJson($this->baseUrl . '/' . $debitCardTransaction->id);
        $response->assertForbidden();
    }

    /**
     * Test customer can see a single debit card transaction attached to his debit card
     *
     * @return void
     */
    public function testCustomerCanSeeADebitCardTransactionAttachedToHisDebitCard()
    {
        $debitCardTransaction = DebitCardTransaction::factory()->for($this->debitCard)->create();
        $response = $this->getJson($this->baseUrl . '/' . $debitCardTransaction->id);
        $response->assertOk()
            ->assertJsonStructure([
                'amount', 'currency_code'
            ]);
    }

}
