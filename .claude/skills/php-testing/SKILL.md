---
name: php-testing
description: "Use when writing or running PHPUnit tests for WordPress code in this project: setting up a test case, faking WordPress functions with Brain\\Monkey, deciding what can and cannot be unit tested, or diagnosing a test that fails for toolchain reasons rather than code reasons."
compatibility: "PHPUnit 10.5 with yoast/phpunit-polyfills 2.0, Brain\\Monkey 2.6, Mockery 1.6. Tests run in the app container on PHP 8.3."
---

# PHP testing

## When to use

- adding tests for anything in `web/app`
- a test fails in a way that points at the harness, not the assertion
- deciding whether something needs a unit test, an integration check, or neither

## Running

```bash
make test                          # whole suite
make test ARGS="--filter Rates"    # one test or group
```

Tests run inside the `app` container, so the PHP version and extension set match what serves the
site. Running them anywhere else proves less than it looks — see the extension-parity trap in the
`wp-stack` skill.

## Layout

```
phpunit.xml.dist     bootstrap, strict flags
tests/bootstrap.php  requires vendor/autoload.php and nothing else
tests/TestCase.php   base class: Monkey setup/teardown, passthrough stubs
tests/*Test.php      the tests
```

`Tests\` is PSR-4 mapped to `tests/` through `autoload-dev`.

## Writing a test

```php
namespace Tests;

use Brain\Monkey\Functions;

final class ThingTest extends TestCase {

    public function test_it_reads_an_option(): void {
        Functions\when( 'get_option' )->justReturn( '42' );

        $this->assertSame( 42, ( new Thing() )->value() );
    }
}
```

Extend `Tests\TestCase`, never `PHPUnit\Framework\TestCase` directly — the base class wires
Brain\Monkey up and down in the right order.

## Traps

Each of these produces a failure that points somewhere other than its cause.

### 1. Autoload order in the bootstrap

`tests/bootstrap.php` requires `vendor/autoload.php` on its first working line. Brain\Monkey
redefines functions through Patchwork, and Patchwork can only intercept files loaded **after**
itself. Load anything testable before it and the redefinitions quietly do nothing: `Functions\when()`
appears to succeed, and the real function runs anyway.

### 2. Set-up and tear-down order, and never closing Mockery yourself

```php
protected function set_up(): void   { parent::set_up();  Monkey\setUp(); }
protected function tear_down(): void { Monkey\tearDown(); parent::tear_down(); }
```

`Monkey\tearDown()` closes Mockery. Calling `Mockery::close()` as well throws on the second call.
The polyfills provide `set_up`/`tear_down` (snake case) so the same tests work across PHPUnit
versions — do not override `setUp`/`tearDown` instead, or the base class never runs.

### 3. An expectation is not an assertion

```php
Functions\expect( 'add_action' )->once();
add_action( 'init', 'cb' );
$this->addToAssertionCount( 1 );   // without this the test is "risky"
```

Mockery verifies expectations during tear-down, which PHPUnit never observes. A test whose only
check is an expectation counts as risky, and `failOnRisky` in `phpunit.xml.dist` turns that into
a failure. That strictness is deliberate — a silently assertion-free test is worse than a failing
one — so count the assertion explicitly.

### 4. `$wpdb` cannot be faked by Brain\Monkey

Brain\Monkey redefines **functions**. `$wpdb` is a global **object**, so `Functions\when()` has no
effect on it. This is not a limitation to work around — it is the reason repositories get an
interface:

- unit tests use an in-memory implementation of the repository interface;
- the real `$wpdb`-backed implementation is exercised against the live database instead, through
  `bin/wp eval-file`.

Do not try to mock `$wpdb` with a stdClass full of closures. It passes, proves nothing, and
breaks on the first `prepare()` with a real placeholder.

### 5. Passthrough stubs

`Tests\TestCase` stubs `esc_html`, `esc_attr`, `esc_url`, `sanitize_text_field`, `wp_unslash`,
`__` and friends to return their input. They carry no logic worth asserting. **Anything with
behaviour** — `get_option`, `wp_remote_get`, `current_user_can` — must be stubbed by the test
itself, so the test states what it depends on.

## What not to unit test

- WordPress itself. Asserting that `add_action` registers a hook tests core, not us.
- Rendering. Asserting on HTML strings breaks on every markup change and catches nothing.
- Database SQL. That belongs in an integration check against the real database.
