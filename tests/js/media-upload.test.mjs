import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const script = readFileSync(resolve('public/admin/assets/js/media-upload.js'), 'utf8');

function loadMediaUpload(capture) {
  window.TypeDockMedia = {
    attachDropZone: vi.fn((area, handlers) => {
      capture.area = area;
      capture.handlers = handlers;
    }),
  };

  window.eval(script);

  if (!capture.handlers) {
    document.dispatchEvent(new Event('DOMContentLoaded'));
  }
}

describe('media-upload.js', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <main class="admin-main">
        <div class="empty-state">No media yet</div>
        <section id="media-upload-area" data-csrf="csrf-token"></section>
      </main>
    `;
    Object.assign(navigator, {
      clipboard: { writeText: vi.fn(() => Promise.resolve()) },
    });
  });

  it('mounts the grid and appends the first uploaded media item', () => {
    const capture = {};
    loadMediaUpload(capture);

    expect(window.TypeDockMedia.attachDropZone).toHaveBeenCalledOnce();
    expect(capture.area).toBe(document.getElementById('media-upload-area'));

    capture.handlers.onUploaded({
      id: 'media-1',
      url: '/uploads/hero.jpg',
      original_filename: 'hero.jpg',
      mime_type: 'image/jpeg',
      alt_text: 'Hero',
    });

    expect(document.querySelector('.empty-state')).toBeNull();
    const grid = document.getElementById('media-grid');
    expect(grid).not.toBeNull();
    expect(grid.children).toHaveLength(1);
    expect(grid.querySelector('.media-item')?.dataset.id).toBe('media-1');
    expect(grid.querySelector('img')?.getAttribute('src')).toBe('/uploads/hero.jpg');
    expect(grid.querySelector('input[name="_csrf_token"]')?.getAttribute('value')).toBe('csrf-token');
    expect(document.getElementById('media-upload-success')?.textContent).toBe('hero.jpg uploaded.');
  });

  it('prepends later uploads without recreating the grid', () => {
    document.querySelector('.empty-state').remove();
    document.getElementById('media-upload-area').insertAdjacentHTML(
      'afterend',
      '<div id="media-grid" class="media-grid"><div class="media-item" data-id="old"></div></div>',
    );

    const capture = {};
    loadMediaUpload(capture);

    capture.handlers.onUploaded({
      id: 'new',
      url: '/uploads/new.pdf',
      original_filename: 'new.pdf',
      mime_type: 'application/pdf',
    });

    const items = [...document.querySelectorAll('.media-item')].map((el) => el.dataset.id);
    expect(items).toEqual(['new', 'old']);
    expect(document.querySelector('.media-icon')?.textContent).toBe('application/pdf');
  });
});
