(() => {
  const key = 'certhub.fontSize';
  const minimum = 90;
  const maximum = 150;
  let size = 100;
  try {
    const saved = Number(localStorage.getItem(key));
    if (Number.isInteger(saved) && saved >= minimum && saved <= maximum && saved % 10 === 0) size = saved;
  } catch (_) { /* Text controls remain usable when storage is disabled. */ }
  function render() {
    document.documentElement.style.setProperty('--font-scale', String(size / 100));
    document.querySelectorAll('[data-font-size]').forEach(element => { element.textContent = `${size}%`; });
    document.querySelectorAll('[data-font-action="decrease"]').forEach(button => { button.disabled = size <= minimum; });
    document.querySelectorAll('[data-font-action="increase"]').forEach(button => { button.disabled = size >= maximum; });
  }
  document.querySelectorAll('[data-font-action]').forEach(button => {
    button.addEventListener('click', () => {
      const action = button.dataset.fontAction;
      size = action === 'reset' ? 100 : Math.max(minimum, Math.min(maximum, size + (action === 'increase' ? 10 : -10)));
      render();
      try { localStorage.setItem(key, String(size)); } catch (_) { /* Optional persistence. */ }
    });
  });
  render();
})();
