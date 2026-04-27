<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Component;

use flight\Engine;
use PHPUnit\Framework\TestCase;
use TypeDock\Component\ComponentDefinition;
use TypeDock\Component\ParamOptionsResolver;

final class ParamOptionsResolverTest extends TestCase
{
    public function testSelectFormOptionsComeFromFormPluginTable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE plugin_form_forms (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
        $pdo->prepare('INSERT INTO plugin_form_forms (id, name) VALUES (?, ?)')->execute(['form-1', 'Contact']);
        $pdo->prepare('INSERT INTO plugin_form_forms (id, name) VALUES (?, ?)')->execute(['form-2', 'Newsletter']);

        \Flight::setEngine(new Engine());
        \Flight::map('db', static fn (): \PDO => $pdo);

        $def = new ComponentDefinition(
            type: 'form',
            name: 'Form',
            params: [[
                'name' => 'form_id',
                'type' => 'select_form',
            ]],
        );

        $enriched = (new ParamOptionsResolver())->enrich($def);

        $this->assertSame('select', $enriched->params[0]['type']);
        $this->assertSame([
            'form-1' => 'Contact',
            'form-2' => 'Newsletter',
        ], $enriched->params[0]['options']);
    }
}
