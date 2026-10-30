const galleryVideos = [...document.querySelectorAll('.video-gallery video')];

for (const image of document.querySelectorAll('.photo-gallery img, .specialist-photo img')) {
  const error = image.closest('figure').querySelector('.photo-error');
  const showError = () => {
    if (!error.hidden) return;
    error.hidden = false;
    console.error('Nie udało się wczytać zdjęcia:', image.src);
  };
  image.addEventListener('error', showError);
  image.addEventListener('load', () => { error.hidden = true; });
  if (image.complete && image.naturalWidth === 0) showError();
}

for (const video of galleryVideos) {
  const error = video.closest('figure').querySelector('.video-error');
  const source = video.querySelector('source');
  const showError = () => {
    if (!error.hidden) return;
    error.hidden = false;
    console.error('Nie udało się odtworzyć filmu:', source.src);
  };
  video.addEventListener('error', showError);
  source.addEventListener('error', showError);
  video.addEventListener('loadeddata', () => { error.hidden = true; });
  video.addEventListener('play', () => {
    for (const other of galleryVideos) {
      if (other !== video) other.pause();
    }
  });
}
