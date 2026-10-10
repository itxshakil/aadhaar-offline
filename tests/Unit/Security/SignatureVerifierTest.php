<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Shakil\AadhaarOffline\Exceptions\ConfigurationException;
use Shakil\AadhaarOffline\Exceptions\InvalidSignatureException;
use Shakil\AadhaarOffline\Security\CertificateStore;
use Shakil\AadhaarOffline\Security\SignatureVerifier;
use Shakil\AadhaarOffline\Testing\Fixtures;

class SignatureVerifierTest extends TestCase
{
    public function test_it_accepts_a_valid_rsa_sha256_signature(): void
    {
        $this->verifier()->verify(Fixtures::signedXml());

        $this->addToAssertionCount(1);
    }

    public function test_it_accepts_a_valid_rsa_sha1_signature(): void
    {
        $this->verifier()->verify(Fixtures::signedXml(algorithm: XMLSecurityKey::RSA_SHA1));

        $this->addToAssertionCount(1);
    }

    public function test_it_accepts_a_der_encoded_certificate(): void
    {
        $verifier = new SignatureVerifier(CertificateStore::fromPaths([Fixtures::certificateFile(der: true)]));

        $verifier->verify(Fixtures::signedXml());

        $this->addToAssertionCount(1);
    }

    public function test_it_tries_every_trusted_certificate(): void
    {
        $store = CertificateStore::fromPem([Fixtures::identity('rotated-out')['cert'], Fixtures::identity()['cert']]);

        (new SignatureVerifier($store))->verify(Fixtures::signedXml());

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_a_tampered_name(): void
    {
        $xml = str_replace('Asha Verma', 'Usha Verma', Fixtures::signedXml());

        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify($xml);
    }

    public function test_it_rejects_a_tampered_mobile_hash(): void
    {
        $original = Fixtures::signedXml();
        $forged = Fixtures::xml(['mobile' => '9000000000']);
        $this->assertSame(1, preg_match('/ m="([a-f0-9]{64})"/', $forged, $forgedHash));
        $xml = (string) preg_replace('/ m="[a-f0-9]{64}"/', ' m="' . ($forgedHash[1] ?? '') . '"', $original);

        $this->assertNotSame($original, $xml);
        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify($xml);
    }

    public function test_it_rejects_a_signature_from_an_untrusted_key(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify(Fixtures::signedXml(identity: 'attacker'));
    }

    public function test_it_rejects_an_unsigned_document(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('exactly one XML signature');

        $this->verifier()->verify(Fixtures::xml());
    }

    public function test_it_rejects_a_second_signature(): void
    {
        $twice = Fixtures::sign(Fixtures::signedXml(), identity: 'attacker');

        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify($twice);
    }

    public function test_it_rejects_a_disallowed_algorithm(): void
    {
        $verifier = new SignatureVerifier(CertificateStore::fromPem([Fixtures::identity()['cert']]), [XMLSecurityKey::RSA_SHA256]);

        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('not allowed');

        $verifier->verify(Fixtures::signedXml(algorithm: XMLSecurityKey::RSA_SHA1));
    }

    public function test_it_requires_a_configured_certificate(): void
    {
        $this->expectException(ConfigurationException::class);

        (new SignatureVerifier(new CertificateStore()))->verify(Fixtures::signedXml());
    }

    public function test_it_reads_the_embedded_certificate_for_diagnostics(): void
    {
        $pem = SignatureVerifier::embeddedCertificate(Fixtures::signedXml(identity: 'attacker'));

        $this->assertNotNull($pem);
        $this->assertSame('attacker', CertificateStore::describePem($pem)['subject']);
    }

    public function test_it_returns_null_when_no_certificate_is_embedded(): void
    {
        $this->assertNull(SignatureVerifier::embeddedCertificate(Fixtures::xml()));
    }

    public function test_an_empty_signature_value_is_a_signature_failure_not_a_configuration_one(): void
    {
        $original = Fixtures::signedXml();
        $xml = (string) preg_replace('#(<(?:ds:)?SignatureValue>)[^<]*(</(?:ds:)?SignatureValue>)#', '$1$2', $original);

        $this->assertNotSame($original, $xml);

        $this->expectException(InvalidSignatureException::class);

        $this->verifier()->verify($xml);
    }

    private function verifier(): SignatureVerifier
    {
        return new SignatureVerifier(CertificateStore::fromPaths([Fixtures::certificateFile()]));
    }
}
