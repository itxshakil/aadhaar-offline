<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;
use Shakil\AadhaarOffline\Security\CertificateStore;
use Shakil\AadhaarOffline\Security\QrSignatureVerifier;
use Shakil\AadhaarOffline\Testing\Fixtures;

class QrSignatureVerifierTest extends TestCase
{
    public function test_it_accepts_a_valid_signature(): void
    {
        $this->verify(Fixtures::secureQrBytes());

        $this->addToAssertionCount(1);
    }

    public function test_it_tries_every_trusted_certificate(): void
    {
        $store = CertificateStore::fromPem([Fixtures::identity('rotated-out')['cert'], Fixtures::identity()['cert']]);
        $bytes = Fixtures::secureQrBytes();

        (new QrSignatureVerifier($store))->verify(mb_substr($bytes, 0, -256, '8bit'), mb_substr($bytes, -256, null, '8bit'));

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_single_changed_byte(): void
    {
        $bytes = Fixtures::secureQrBytes();
        $bytes = str_replace('Asha', 'Usha', $bytes);

        $this->expectException(InvalidSignatureException::class);

        $this->verify($bytes);
    }

    public function test_it_rejects_a_signature_from_an_untrusted_key(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verify(Fixtures::secureQrBytes(identity: 'attacker'));
    }

    public function test_it_rejects_a_signature_of_the_wrong_length(): void
    {
        $this->expectException(InvalidSignatureException::class);

        (new QrSignatureVerifier(CertificateStore::fromPem([Fixtures::identity()['cert']])))->verify('data', 'short');
    }

    public function test_it_needs_a_configured_certificate(): void
    {
        $this->expectException(ConfigurationException::class);

        (new QrSignatureVerifier(new CertificateStore()))->verify('data', str_repeat("\x00", 256));
    }

    private function verify(string $bytes): void
    {
        (new QrSignatureVerifier(CertificateStore::fromPem([Fixtures::identity()['cert']])))
            ->verify(mb_substr($bytes, 0, -256, '8bit'), mb_substr($bytes, -256, null, '8bit'));
    }
}
