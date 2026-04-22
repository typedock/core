<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateRedirects extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('redirects', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('source_path', 2000);
            $t->string('target_url', 2000);
            $t->integer('status_code')->default(301);
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->unique(['source_path'], ['source_path' => 255]);
        });
    }
}
