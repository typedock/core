function csrfToken() {
  return document.querySelector('input[name="_csrf_token"]')?.value || ''
}

export async function uploadImage(file) {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('_csrf_token', csrfToken())

  const resp = await fetch('/admin/api/media/upload', {
    method: 'POST',
    body: formData,
    credentials: 'same-origin',
  })
  if (!resp.ok) throw new Error(`Upload failed: ${resp.status}`)
  const payload = await resp.json()
  // Admin upload returns { ok: true, media: {...} }; external API returns { data: {...} }.
  const media = payload.media || payload.data || payload
  return {
    src: media.url,
    alt: media.alt_text || file.name || '',
    mediaId: media.id || null,
  }
}
