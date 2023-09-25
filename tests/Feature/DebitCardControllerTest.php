<?php

namespace Tests\Feature;

use App\Models\DebitCard;
use App\Models\DebitCardTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DebitCardControllerTest extends TestCase
{
    use RefreshDatabase,WithFaker;

    /**
     * Base url for debit card endpoints
     *
     * @var string
     */
    private string $baseUrl = 'api/debit-cards';

    /**
     * @var User
     */
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Passport::actingAs($this->user);
    }

    /**
     * Test customer can see a list of debit cards
     *
     * @return void
     */
    public function testCustomerCanSeeAListOfDebitCards()
    {
        $debitCardsCount = $this->faker->numberBetween(1, 10);
        DebitCard::factory()->for($this->user)->count($debitCardsCount)->active()->create();
        DebitCard::factory()->for($this->user)->count($debitCardsCount)->expired()->create();
        $response = $this->getJson($this->baseUrl);
        $response->assertOk()
            ->assertJsonStructure([
                [
                    'id','number','expiration_date','is_active'
                ]
            ]);

        $response->assertJsonCount($debitCardsCount);
    }

    /**
     * Test customer cannot see a list of debit cards of other customers
     *
     * @return void
     */
    public function testCustomerCannotSeeAListOfDebitCardsOfOtherCustomers()
    {
        $debitCardsCount = $this->faker->numberBetween(1, 10);
        $debitCards = DebitCard::factory()->for($this->user)->count($debitCardsCount)->active()->create();
        DebitCard::factory()->for(User::factory())->count($debitCardsCount)->active()->create();
        $response = $this->getJson($this->baseUrl);
        $response->assertOk()
            ->assertJsonStructure([
                [
                    'id','number','expiration_date','is_active'
                ]
            ]);

        foreach($response->json() as $debitCard){
            $this->assertContains($debitCard['id'], $debitCards->pluck('id'));
        }
    }

    /**
     * Test customer can create a debit card
     *
     * @return void
     */
    public function testCustomerCanCreateADebitCard()
    {
        $response = $this->postJson($this->baseUrl, [
            'type' => $this->faker->randomElement(['visa', 'mastercard']),
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'id','number','expiration_date','is_active'
            ]);

        $this->assertDatabaseHas('debit_cards', [
            'id' => $response->json('id'),
            'user_id' => $this->user->id,
            'type' => $response->json('type'),
            'number' => $response->json('number'),
        ]);
    }

    /**
     * Test customer can see a single debit card details
     *
     * @return void
     */
    public function testCustomerCanSeeASingleDebitCardDetails()
    {
        $debitCard = DebitCard::factory()->for($this->user)->create();
        $response = $this->getJson($this->baseUrl . '/' . $debitCard->id);
        $response->assertOk()
            ->assertJsonStructure([
                'id','number','expiration_date','is_active'
            ]);

        $response->assertJson([
            'id' => $debitCard->id,
            'number' => $debitCard->number,
            'is_active' => $debitCard->is_active,
            'expiration_date' => $debitCard->expiration_date->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Test customer cannot see a single debit card details of other customers
     *
     * @return void
     */
    public function testCustomerCannotSeeASingleDebitCardDetails()
    {
        $debitCard = DebitCard::factory()->for(User::factory())->create();
        $response = $this->getJson($this->baseUrl . '/' . $debitCard->id);
        $response->assertForbidden();
    }

    /**
     * Test customer can activate a debit card
     *
     * @return void
     */
    public function testCustomerCanActivateADebitCard()
    {
        $debitCard = DebitCard::factory()->for($this->user)->expired()->create();
        $response = $this->putJson($this->baseUrl . '/' . $debitCard->id,[
            'is_active' => true,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'id','number','expiration_date','is_active'
            ]);

        $response->assertJson([
            'id' => $debitCard->id,
            'number' => $debitCard->number,
            'is_active' => true,
            'expiration_date' => $debitCard->expiration_date->format('Y-m-d H:i:s'),
        ]);

        $this->assertDatabaseHas('debit_cards', [
            'id' => $debitCard->id,
            'disabled_at' => null,
        ]);
    }

    /**
     * Test customer cannot activate a debit card with wrong validation
     *
     * @return void
     */
    public function testCustomerCanDeactivateADebitCard()
    {
        $debitCard = DebitCard::factory()->for($this->user)->active()->create();
        $response = $this->putJson($this->baseUrl . '/' . $debitCard->id,[
            'is_active' => false,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'id','number','expiration_date','is_active'
            ]);

        $response->assertJson([
            'id' => $debitCard->id,
            'number' => $debitCard->number,
            'is_active' => false,
            'expiration_date' => $debitCard->expiration_date->format('Y-m-d H:i:s'),
        ]);

        $this->assertDatabaseHas('debit_cards', [
            'id' => $debitCard->id,
            'disabled_at' => now(),
        ]);
    }

    /**
     * Test customer cannot update a debit card with wrong validation
     *
     * @return void
     */
    public function testCustomerCannotUpdateADebitCardWithWrongValidation()
    {
        $debitCard = DebitCard::factory()->for($this->user)->create();
        $response = $this->putJson($this->baseUrl . '/' . $debitCard->id,[
            'is_active' => 'wrong',
        ]);
        $response->assertStatus(422);
    }

    /**
     * Test customer can delete a debit card
     *
     * @return void
     */
    public function testCustomerCanDeleteADebitCard()
    {
        $debitCard = DebitCard::factory()->for($this->user)->create();
        $response = $this->deleteJson($this->baseUrl . '/' . $debitCard->id);
        $response->assertNoContent();
        $this->assertSoftDeleted('debit_cards', [
            'id' => $debitCard->id,
        ]);
    }

    /**
     * Test customer cannot delete a debit card with transaction
     *
     * @return void
     */
    public function testCustomerCannotDeleteADebitCardWithTransaction()
    {
        $debitCard = DebitCard::factory()->for($this->user)->create();
        DebitCardTransaction::factory()->for($debitCard)->count(rand(1,10))->create();
        $response = $this->deleteJson($this->baseUrl . '/' . $debitCard->id);
        $response->assertForbidden();
    }

    /**
     * Test customer cannot delete a debit card of other customers
     *
     * @return void
     */
    public function testCustomerCannotDeleteADebitCardOfOtherCustomers()
    {
        $debitCard = DebitCard::factory()->for(User::factory())->create();
        $response = $this->deleteJson($this->baseUrl . '/' . $debitCard->id);
        $response->assertForbidden();
    }

    /**
     * Test customer cannot update a debit card of other customers
     *
     * @return void
     */
    public function testCustomerCannotUpdateADebitCardOfOtherCustomers()
    {
        $debitCard = DebitCard::factory()->for(User::factory())->create();
        $response = $this->putJson($this->baseUrl . '/' . $debitCard->id,[
            'is_active' => true,
        ]);
        $response->assertForbidden();
    }

    /**
     * Test customer cannot activate a debit card of other customers
     *
     * @return void
     */
    public function testCustomerCannotActivateADebitCardOfOtherCustomers()
    {
        $debitCard = DebitCard::factory()->for(User::factory())->create();
        $response = $this->putJson($this->baseUrl . '/' . $debitCard->id,[
            'is_active' => true,
        ]);
        $response->assertForbidden();
    }

    /**
     * Test customer cannot deactivate a debit card of other customers
     *
     * @return void
     */
    public function testCustomerCannotDeactivateADebitCardOfOtherCustomers()
    {
        $debitCard = DebitCard::factory()->for(User::factory())->create();
        $response = $this->putJson($this->baseUrl . '/' . $debitCard->id,[
            'is_active' => false,
        ]);
        $response->assertForbidden();
    }


    /**
     * Test customer cannot see a single debit card details of other customers
     *
     * @return void
     */
    public function testCustomerCannotSeeASingleDebitCardDetailsOfOtherCustomers()
    {
        $debitCard = DebitCard::factory()->for(User::factory())->create();
        $response = $this->getJson($this->baseUrl . '/' . $debitCard->id);
        $response->assertForbidden();
    }
}
