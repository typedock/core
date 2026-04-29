<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use TypeDock\Auth\PermissionChecker;

/**
 * Pin the RBAC matrix against the doc24 #1 acceptance list so a refactor
 * that quietly relaxes a rule (e.g. letting contributor publish) is caught
 * by CI instead of by an audit.
 *
 * Each cluster of tests mirrors one line item from
 * `docs-internal/24-security-audit-action-plan.md` §1.
 */
final class PermissionCheckerTest extends TestCase
{
    private PermissionChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new PermissionChecker();
    }

    // -----------------------------------------------------------------
    // Posts
    // -----------------------------------------------------------------

    public function test_contributor_cannot_publish_post(): void
    {
        self::assertFalse($this->checker->can($this->user('contributor'), 'posts:publish'));
    }

    public function test_author_can_publish_post(): void
    {
        self::assertTrue($this->checker->can($this->user('author'), 'posts:publish'));
    }

    public function test_author_cannot_edit_others_posts(): void
    {
        self::assertFalse($this->checker->can($this->user('author'), 'posts:edit_any'));
        self::assertTrue($this->checker->can($this->user('author'), 'posts:edit_own'));
    }

    public function test_editor_can_edit_any_post(): void
    {
        self::assertTrue($this->checker->can($this->user('editor'), 'posts:edit_any'));
        self::assertTrue($this->checker->can($this->user('editor'), 'posts:delete_any'));
    }

    // -----------------------------------------------------------------
    // Pages — stricter than posts (publish is editor/admin only)
    // -----------------------------------------------------------------

    public function test_contributor_and_author_cannot_publish_page(): void
    {
        self::assertFalse($this->checker->can($this->user('contributor'), 'pages:publish'));
        self::assertFalse($this->checker->can($this->user('author'),      'pages:publish'));
    }

    public function test_only_editor_admin_can_delete_pages(): void
    {
        // pages:delete_own intentionally absent from the matrix — pages are
        // site structure, not personal content. Only `delete_any` exists.
        self::assertFalse($this->checker->can($this->user('contributor'), 'pages:delete_any'));
        self::assertFalse($this->checker->can($this->user('author'),      'pages:delete_any'));
        self::assertTrue($this->checker->can($this->user('editor'),       'pages:delete_any'));
        self::assertTrue($this->checker->can($this->user('admin'),        'pages:delete_any'));
    }

    // -----------------------------------------------------------------
    // Media
    // -----------------------------------------------------------------

    public function test_all_roles_can_upload_media(): void
    {
        foreach (['contributor', 'author', 'editor', 'admin'] as $role) {
            self::assertTrue(
                $this->checker->can($this->user($role), 'media:upload'),
                "{$role} should be able to upload media"
            );
        }
    }

    public function test_contributor_cannot_manage_others_media(): void
    {
        self::assertFalse($this->checker->can($this->user('contributor'), 'media:manage_any'));
        self::assertFalse($this->checker->can($this->user('author'),      'media:manage_any'));
        self::assertTrue($this->checker->can($this->user('editor'),       'media:manage_any'));
    }

    // -----------------------------------------------------------------
    // Site management — contributor denied across the board
    // -----------------------------------------------------------------

    public function test_contributor_cannot_manage_redirects_slots_menus_categories(): void
    {
        $contributor = $this->user('contributor');
        // Redirect plugin uses settings:manage; menus/slots/categories have
        // their own keys. The audit's intent is "contributor sees none of
        // these" — pin every key the audit list mentioned.
        foreach (['menus:manage', 'slots:manage', 'categories:manage', 'settings:manage'] as $perm) {
            self::assertFalse(
                $this->checker->can($contributor, $perm),
                "contributor must not have {$perm}"
            );
        }
    }

    // -----------------------------------------------------------------
    // Raw HTML capability (doc24 #6)
    // -----------------------------------------------------------------

    public function test_unfiltered_html_is_editor_admin_only(): void
    {
        self::assertFalse($this->checker->can($this->user('contributor'), 'content:unfiltered_html'));
        self::assertFalse($this->checker->can($this->user('author'),      'content:unfiltered_html'));
        self::assertTrue($this->checker->can($this->user('editor'),       'content:unfiltered_html'));
        self::assertTrue($this->checker->can($this->user('admin'),        'content:unfiltered_html'));
    }

    // -----------------------------------------------------------------
    // Role hierarchy sanity
    // -----------------------------------------------------------------

    public function test_role_hierarchy_admin_implies_editor(): void
    {
        self::assertTrue($this->checker->hasRole($this->user('admin'), 'editor'));
        self::assertTrue($this->checker->hasRole($this->user('editor'), 'editor'));
        self::assertFalse($this->checker->hasRole($this->user('author'), 'editor'));
    }

    public function test_unknown_permission_is_denied(): void
    {
        self::assertFalse($this->checker->can($this->user('admin'), 'totally:made:up'));
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $role): array
    {
        return ['id' => 'u', 'role' => $role];
    }
}
