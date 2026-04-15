<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMedia extends AbstractMigration
{
    public function change(): void
    {
        // media table
        $media = $this->table('media', ['id' => false, 'primary_key' => ['id']]);
        $media
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('path', 'string', ['limit' => 1000])
            ->addColumn('original_filename', 'string', ['limit' => 500])
            ->addColumn('mime_type', 'string', ['limit' => 127])
            ->addColumn('file_size', 'biginteger', [])
            ->addColumn('width', 'integer', ['null' => true, 'default' => null])
            ->addColumn('height', 'integer', ['null' => true, 'default' => null])
            ->addColumn('alt_text', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('caption', 'text', ['null' => true, 'default' => null])
            ->addColumn('focal_point_x', 'float', ['null' => true, 'default' => null])
            ->addColumn('focal_point_y', 'float', ['null' => true, 'default' => null])
            ->addColumn('folder', 'string', ['limit' => 500, 'default' => '/'])
            ->addColumn('thumbnails', 'text', ['null' => true, 'default' => null])
            ->addColumn('uploaded_by', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['folder'])
            ->addIndex(['mime_type'])
            ->addForeignKey('uploaded_by', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }
}
