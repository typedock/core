<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateSiteOptions extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('site_options', function (Blueprint $t) {
            $t->string('key_name', 255);
            $t->text('value')->null();
            $t->string('group_name', 100)->null();
            $t->datetime('updated_at');
            $t->primary(['key_name']);
            $t->index(['group_name']);
        });
    }
}
