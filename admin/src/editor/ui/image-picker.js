function mediaToImageAttrs(media) {
  return {
    src: media.url,
    alt: media.alt_text || media.original_filename || '',
    title: media.original_filename || '',
    width: media.width || null,
    mediaId: media.id || null,
  }
}

export function openImagePicker(editor) {
  const picker = window.TypeDockMedia
  if (!picker?.openPicker) {
    console.error('TypeDock media picker is not available.')
    return
  }

  picker.openPicker({
    accept: 'image',
    onSelect: (media) => {
      if (!media?.url) return
      editor.chain().focus().setImage(mediaToImageAttrs(media)).run()
    },
  })
}
