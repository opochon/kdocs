<?php
/**
 * Tests for Validator class
 */

namespace Tests\Unit\Core;

use KDocs\Core\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    public function testRequiredRulePasses(): void
    {
        $validator = Validator::make(
            ['name' => 'John'],
            ['name' => 'required']
        );

        $this->assertTrue($validator->passes());
    }

    public function testRequiredRuleFails(): void
    {
        $validator = Validator::make(
            ['name' => ''],
            ['name' => 'required']
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('name', $validator->errors());
    }

    public function testEmailRulePasses(): void
    {
        $validator = Validator::make(
            ['email' => 'test@example.com'],
            ['email' => 'email']
        );

        $this->assertTrue($validator->passes());
    }

    public function testEmailRuleFails(): void
    {
        $validator = Validator::make(
            ['email' => 'not-an-email'],
            ['email' => 'email']
        );

        $this->assertTrue($validator->fails());
    }

    public function testIntegerRulePasses(): void
    {
        $validator = Validator::make(
            ['age' => 25],
            ['age' => 'integer']
        );

        $this->assertTrue($validator->passes());
    }

    public function testIntegerRuleFailsWithString(): void
    {
        $validator = Validator::make(
            ['age' => 'twenty-five'],
            ['age' => 'integer']
        );

        $this->assertTrue($validator->fails());
    }

    public function testMinLengthPasses(): void
    {
        $validator = Validator::make(
            ['password' => '12345678'],
            ['password' => 'min:8']
        );

        $this->assertTrue($validator->passes());
    }

    public function testMinLengthFails(): void
    {
        $validator = Validator::make(
            ['password' => '1234'],
            ['password' => 'min:8']
        );

        $this->assertTrue($validator->fails());
    }

    public function testMaxLengthPasses(): void
    {
        $validator = Validator::make(
            ['title' => 'Short'],
            ['title' => 'max:100']
        );

        $this->assertTrue($validator->passes());
    }

    public function testMaxLengthFails(): void
    {
        $validator = Validator::make(
            ['title' => str_repeat('a', 101)],
            ['title' => 'max:100']
        );

        $this->assertTrue($validator->fails());
    }

    public function testInRulePasses(): void
    {
        $validator = Validator::make(
            ['status' => 'approved'],
            ['status' => 'in:pending,approved,rejected']
        );

        $this->assertTrue($validator->passes());
    }

    public function testInRuleFails(): void
    {
        $validator = Validator::make(
            ['status' => 'invalid'],
            ['status' => 'in:pending,approved,rejected']
        );

        $this->assertTrue($validator->fails());
    }

    public function testNullableAllowsEmpty(): void
    {
        $validator = Validator::make(
            ['nickname' => ''],
            ['nickname' => 'nullable|min:3']
        );

        $this->assertTrue($validator->passes());
    }

    public function testMultipleRules(): void
    {
        $validator = Validator::make(
            ['email' => 'test@example.com'],
            ['email' => 'required|email']
        );

        $this->assertTrue($validator->passes());
    }

    public function testValidatedReturnsOnlyValidatedFields(): void
    {
        $validator = Validator::make(
            ['name' => 'John', 'extra' => 'value'],
            ['name' => 'required']
        );

        $validated = $validator->validated();

        $this->assertArrayHasKey('name', $validated);
        $this->assertArrayNotHasKey('extra', $validated);
    }

    public function testUrlRulePasses(): void
    {
        $validator = Validator::make(
            ['website' => 'https://example.com'],
            ['website' => 'url']
        );

        $this->assertTrue($validator->passes());
    }

    public function testUrlRuleFails(): void
    {
        $validator = Validator::make(
            ['website' => 'not-a-url'],
            ['website' => 'url']
        );

        $this->assertTrue($validator->fails());
    }

    public function testBetweenRulePasses(): void
    {
        $validator = Validator::make(
            ['username' => 'john_doe'],
            ['username' => 'between:3,20']
        );

        $this->assertTrue($validator->passes());
    }

    public function testBetweenRuleFailsTooShort(): void
    {
        $validator = Validator::make(
            ['username' => 'ab'],
            ['username' => 'between:3,20']
        );

        $this->assertTrue($validator->fails());
    }

    public function testConfirmedRulePasses(): void
    {
        $validator = Validator::make(
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
            ['password' => 'confirmed']
        );

        $this->assertTrue($validator->passes());
    }

    public function testConfirmedRuleFails(): void
    {
        $validator = Validator::make(
            ['password' => 'secret123', 'password_confirmation' => 'different'],
            ['password' => 'confirmed']
        );

        $this->assertTrue($validator->fails());
    }

    public function testSanitizeTrim(): void
    {
        $data = Validator::sanitize(
            ['name' => '  John  '],
            ['name' => 'trim']
        );

        $this->assertEquals('John', $data['name']);
    }

    public function testSanitizeStripTags(): void
    {
        $data = Validator::sanitize(
            ['html' => '<script>alert("xss")</script>Hello'],
            ['html' => 'strip_tags']
        );

        $this->assertEquals('alert("xss")Hello', $data['html']);
    }

    public function testSanitizeMultiple(): void
    {
        $data = Validator::sanitize(
            ['name' => '  <b>John</b>  '],
            ['name' => 'trim|strip_tags']
        );

        $this->assertEquals('John', $data['name']);
    }

    public function testErrorMethodReturnsFirstError(): void
    {
        $validator = Validator::make(
            ['name' => ''],
            ['name' => 'required']
        );

        $this->assertNotNull($validator->error('name'));
        $this->assertNull($validator->error('email'));
    }

    public function testErrorMessagesAreInFrench(): void
    {
        $validator = Validator::make(
            ['name' => ''],
            ['name' => 'required']
        );

        $this->assertStringContainsString('requis', $validator->error('name'));
    }
}
