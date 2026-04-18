<?php

namespace Tests\Feature;

use App\Support\ArbiterPromptContextAssembler;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('spec')]
#[Group('spec-ARB-009')]
class ArbiterExchangeRulesFileTest extends TestCase
{
    public function test_arbiter_rules_digest_file_exists_and_is_non_trivial(): void
    {
        $path = resource_path('prompts/arbiter_exchange_rules.md');
        $this->assertFileExists($path);
        $raw = (string) file_get_contents($path);
        $this->assertGreaterThan(200, strlen($raw));
        $this->assertStringContainsString('deal_machine_state', $raw);
        $this->assertStringContainsString('Arbiter LLM digest', $raw);

        $stub = base_path('docs/ARBITER_EXCHANGE_RULES.md');
        $this->assertFileExists($stub);
        $this->assertStringContainsString('resources/prompts/arbiter_exchange_rules.md', (string) file_get_contents($stub));
    }

    public function test_arbiter_prompt_context_assembler_loads_digest(): void
    {
        $digest = ArbiterPromptContextAssembler::loadRulesDigest();
        $this->assertGreaterThan(100, strlen($digest));
        $this->assertStringContainsString('deal_machine_state', $digest);
    }
}
