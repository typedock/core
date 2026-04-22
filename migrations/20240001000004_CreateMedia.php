<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateMedia extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('media', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('path', 1000);
            $t->string('original_filename', 500);
            $t->string('mime_type', 127);
            $t->bigInteger('file_size');
            $t->integer('width')->null();
            $t->integer('height')->null();
            $t->string('alt_text', 500)->null();
            $t->text('caption')->null();
            $t->float('focal_point_x')->null();
            $t->float('focal_point_y')->null();
            $t->string('folder', 500)->default('/');
            $t->text('thumbnails')->null();
            $t->string('uploaded_by', 36)->null();
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->index(['folder'], ['folder' => 255]);
            $t->index(['mime_type']);
            $t->foreign('uploaded_by', 'users', 'id')->nullOnDelete();
        });
    }
}
