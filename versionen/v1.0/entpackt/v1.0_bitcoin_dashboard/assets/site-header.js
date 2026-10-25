(() => {
  const isLocalPreview = () => (
    window.location.protocol === 'file:'
    || ['localhost', '127.0.0.1', '[::1]'].includes(window.location.hostname)
  );

  const getLanguageTarget = (button) => {
    if (isLocalPreview() && button.dataset.languageFileTarget) {
      return button.dataset.languageFileTarget;
    }
    return button.dataset.languageTarget;
  };

  const bindLanguageSwitches = () => {
    document.querySelectorAll('[data-language-target]').forEach((button) => {
      if (button.dataset.languageBound === 'true') return;
      button.dataset.languageBound = 'true';
      button.addEventListener('click', () => {
        const target = getLanguageTarget(button);
        if (target) window.location.href = target;
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindLanguageSwitches, { once: true });
  } else {
    bindLanguageSwitches();
  }
})();
