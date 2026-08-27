<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests\Unit;

use Core45\AiCore\Support\AiOperations\ClientReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClientReferenceTest extends TestCase
{
    #[Test]
    public function it_builds_valid_surface_specific_references(): void
    {
        $detectionReference = ClientReference::forDetection(123);
        $humanizationReference = ClientReference::forHumanization(456);

        $this->assertSame('v1.d.123', $detectionReference);
        $this->assertSame('v1.h.456', $humanizationReference);
        $this->assertTrue(ClientReference::isValid($detectionReference));
        $this->assertTrue(ClientReference::isValid($humanizationReference));
        $this->assertNotSame(ClientReference::forDetection(1), ClientReference::forHumanization(1));
    }

    #[Test]
    public function it_accepts_a_twenty_digit_identifier(): void
    {
        $reference = 'v1.d.12345678901234567890';

        $this->assertLessThanOrEqual(255, strlen($reference));
        $this->assertTrue(ClientReference::isValid($reference));
    }

    /** @return array<string, array{string}> */
    public static function invalidReferences(): array
    {
        return [
            'empty' => [''],
            'wrong version' => ['v2.d.1'],
            'wrong surface' => ['v1.x.1'],
            'non-numeric id' => ['v1.d.one'],
            'space' => ['v1.d.1 2'],
            'slash' => ['v1.d.1/2'],
            'backslash' => ['v1.d.1\\2'],
            'colon' => ['v1.d.1:2'],
            'too long' => [str_repeat('a', 256)],
        ];
    }

    #[Test]
    #[DataProvider('invalidReferences')]
    public function it_rejects_invalid_references(string $reference): void
    {
        $this->assertFalse(ClientReference::isValid($reference));
    }
}
