<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    public function test_completa_la_lada_de_los_numeros_nacionales(): void
    {
        $this->assertSame('+525512345678', PhoneNumber::normalize('5512345678', '52'));
        $this->assertSame('+525512345678', PhoneNumber::normalize('55 1234 5678', '52'));
        $this->assertSame('+525512345678', PhoneNumber::normalize('(55) 1234-5678', '52'));
    }

    public function test_respeta_los_numeros_que_ya_traen_lada(): void
    {
        $this->assertSame('+5215512345678', PhoneNumber::normalize('+5215512345678'));
        $this->assertSame('+5215512345678', PhoneNumber::normalize('+52 1 55 1234 5678'));
        $this->assertSame('+5215512345678', PhoneNumber::normalize('5215512345678', '52'));
        $this->assertSame('+34600123456', PhoneNumber::normalize('+34 600 123 456'));
    }

    public function test_acepta_el_prefijo_internacional_00(): void
    {
        $this->assertSame('+525512345678', PhoneNumber::normalize('00525512345678'));
    }

    public function test_rechaza_numeros_invalidos(): void
    {
        $this->assertNull(PhoneNumber::normalize(''));
        $this->assertNull(PhoneNumber::normalize(null));
        $this->assertNull(PhoneNumber::normalize('no-es-un-telefono'));
        $this->assertNull(PhoneNumber::normalize('123'));
        $this->assertNull(PhoneNumber::normalize('+0123456789'));
    }

    public function test_valida_y_extrae_los_digitos(): void
    {
        $this->assertTrue(PhoneNumber::isValid('+525512345678'));
        $this->assertFalse(PhoneNumber::isValid('525512345678'));
        $this->assertFalse(PhoneNumber::isValid('+52'));
        $this->assertSame('525512345678', PhoneNumber::toDigits('+52 55 1234 5678'));
    }
}
