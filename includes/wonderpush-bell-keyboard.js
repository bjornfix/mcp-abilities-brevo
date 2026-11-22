/**
 * Preserve the original SDK actions while supplying keyboard semantics to its bell.
 */
(() => {
	const instances = new WeakSet();
	const collapsed = 'wonderpush-collapsed';

	function attach(bell) {
		if (instances.has(bell)) return;

		const find = name => bell.querySelector('.wonderpush-' + name);
		const trigger = find('icon-container');
		const dialog = find('dialog');
		const help = find('help');
		const settings = find('dialog-settings-button');
		const advanced = find('dialog-advanced-settings');
		const title = find('dialog-title');
		const description = find('dialog-advanced-settings-description');
		if (!trigger || !dialog || !help || !settings || !advanced || !title || !description) return;

		instances.add(bell);
		const controls = [
			trigger, settings, find('dialog-button'),
			find('dialog-advanced-settings-download-button'), find('dialog-advanced-settings-clear-button'),
		];
		const enhanced = new Set();
		for (const control of controls) {
			if (control?.tagName !== 'DIV' || control.hasAttribute('tabindex') || control.hasAttribute('role')) continue;
			control.setAttribute('role', 'button');
			control.tabIndex = 0;
			enhanced.add(control);
		}
		if (enhanced.has(trigger)) trigger.setAttribute('aria-label', title.textContent.trim());
		if (enhanced.has(settings)) settings.setAttribute('aria-label', description.textContent.trim());

		const open = element => !element.classList.contains(collapsed);
		const sync = () => {
			const dialogOpen = open(dialog);
			const advancedOpen = dialogOpen && open(advanced);
			const helpOpen = open(help);
			if (!dialogOpen && dialog.contains(document.activeElement)) trigger.focus();
			if (!advancedOpen && advanced.contains(document.activeElement)) settings.focus();
			dialog.inert = !dialogOpen;
			advanced.inert = !advancedOpen;
			help.inert = !helpOpen;
			if (enhanced.has(trigger)) trigger.setAttribute('aria-expanded', String(dialogOpen || helpOpen));
			if (enhanced.has(settings)) settings.setAttribute('aria-expanded', String(advancedOpen));
		};
		const watch = new MutationObserver(sync);
		for (const panel of [dialog, advanced, help]) watch.observe(panel, { attributes: true, attributeFilter: ['class'] });
		sync();

		bell.addEventListener('keydown', event => {
			if (event.key === 'Escape' && (open(dialog) || open(help))) {
				event.preventDefault();
				event.stopPropagation();
				if (open(dialog)) trigger.click();
				if (open(help)) help.classList.add(collapsed);
				trigger.focus();
			} else if (enhanced.has(event.target) && (event.key === 'Enter' || event.key === ' ')) {
				event.preventDefault();
				if (event.key === 'Enter' && !event.repeat) event.target.click();
			}
		});
		bell.addEventListener('keyup', event => {
			if (event.key === ' ' && enhanced.has(event.target)) {
				event.preventDefault();
				event.target.click();
			}
		});
	}

	const bellSelector = 'body > .wonderpush-bell';
	const bind = () => document.querySelectorAll(bellSelector).forEach(attach);
	if (!document.body) return;
	new MutationObserver(bind).observe(document.body, { childList: true });
	bind();
})();
