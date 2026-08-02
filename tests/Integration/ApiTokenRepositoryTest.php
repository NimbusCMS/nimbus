<?php

declare(strict_types=1);

namespace Nimbus\Tests\Integration;

use Nimbus\Api\ApiTokenRepository;

final class ApiTokenRepositoryTest extends IntegrationTestCase
{
    private ApiTokenRepository $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new ApiTokenRepository($this->db);
    }

    public function test_a_created_token_is_prefixed_and_resolvable(): void
    {
        $plain = $this->tokens->create('My app');

        self::assertStringStartsWith('nbt_', $plain);
        $token = $this->tokens->findByPlaintext($plain);
        self::assertNotNull($token);
        self::assertSame('My app', $token->name);
    }

    public function test_only_the_hash_is_stored_never_the_plaintext(): void
    {
        $plain = $this->tokens->create('Secret');

        $stored = $this->db->selectOne('SELECT token_hash FROM nb_api_tokens')['token_hash'];
        self::assertNotSame($plain, $stored, 'the plaintext must never be persisted');
        self::assertSame(hash('sha256', $plain), $stored);
    }

    public function test_the_wrong_token_resolves_to_null(): void
    {
        $this->tokens->create('Real');

        self::assertNull($this->tokens->findByPlaintext('nbt_' . str_repeat('0', 40)));
        self::assertNull($this->tokens->findByPlaintext('not-even-prefixed'));
    }

    public function test_each_token_is_unique(): void
    {
        self::assertNotSame($this->tokens->create('A'), $this->tokens->create('B'));
    }

    public function test_touch_records_last_used(): void
    {
        $plain = $this->tokens->create('App');
        $token = $this->tokens->findByPlaintext($plain);
        self::assertNotNull($token);
        self::assertNull($token->lastUsedAt, 'a fresh token has never been used');

        $this->tokens->touch($token->id);

        self::assertNotNull($this->db->selectOne('SELECT last_used_at FROM nb_api_tokens WHERE id = :i', ['i' => $token->id])['last_used_at']);
    }

    public function test_abilities_round_trip(): void
    {
        $plain = $this->tokens->create('Scoped', ['read']);

        self::assertSame(['read'], $this->tokens->findByPlaintext($plain)->abilities);
        // No abilities stores NULL, reads back as an empty list.
        $plain2 = $this->tokens->create('Plain');
        self::assertSame([], $this->tokens->findByPlaintext($plain2)->abilities);
    }
}
