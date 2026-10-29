const galleryVideos = [...document.querySelectorAll('.video-gallery video')];

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
