<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Shakil\AadhaarOffline\Data\Address;

class AddressTest extends TestCase
{
    public function test_it_maps_uidai_attribute_names(): void
    {
        $address = Address::fromUidaiAttributes([
            'careof' => 'S/O: Test',
            'house' => '12',
            'street' => ' ',
            'loc' => 'Lajpat Nagar',
            'vtc' => 'New Delhi',
            'po' => 'Lajpat Nagar',
            'subdist' => 'Defence Colony',
            'dist' => 'New Delhi',
            'state' => 'Delhi',
            'country' => 'India',
            'pc' => '110024',
        ]);

        $this->assertSame('S/O: Test', $address->careOf);
        $this->assertNull($address->street);
        $this->assertSame('Lajpat Nagar', $address->locality);
        $this->assertSame('110024', $address->pincode);
        $this->assertSame('Defence Colony', $address->toArray()['sub_district']);
    }

    public function test_formatted_skips_empty_and_duplicate_parts(): void
    {
        $address = Address::fromUidaiAttributes([
            'house' => '12',
            'loc' => 'Lajpat Nagar',
            'po' => 'Lajpat Nagar',
            'dist' => 'New Delhi',
            'vtc' => 'New Delhi',
            'state' => 'Delhi',
            'pc' => '110024',
        ]);

        $this->assertSame('12, Lajpat Nagar, New Delhi, Delhi, 110024', $address->formatted());
    }
}
