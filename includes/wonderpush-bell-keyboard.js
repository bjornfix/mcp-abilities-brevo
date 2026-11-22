/**
 * Preserve the original SDK actions while supplying keyboard semantics to its bell.
 */
(() => {
	const instances = new WeakSet();
	const collapsed = 'wonderpush-collapsed';
	const bellSelector = 'body > .wonderpush-bell';

	function watchPlacement() {
		const marker = document.querySelector('[data-mcp-brevo-content]');
		const stylesheet = document.getElementById('mcp-brevo-wonderpush-inline-bell-css');
		if (!marker || !stylesheet || !window.ResizeObserver) return () => {};

		let regions;
		let peer;
		try {
			regions = [...document.querySelectorAll(marker.dataset.mcpBrevoContent)];
			peer = document.querySelector(marker.dataset.mcpBrevoFloatingControl);
		} catch {
			return () => {};
		}
		if (!regions.length || !peer) return () => {};

		const flow = 'data-mcp-brevo-controls-flow';
		const fallback = stylesheet.media;
		const mobile = window.matchMedia(fallback);
		const inset = value => Number.parseFloat(value) || 0;
		const overlaps = (box, regions) => box.width > 0 && regions.some(region => {
			const bounds = region.getBoundingClientRect();
			if (!bounds.width) return false;
			const css = getComputedStyle(region);
			return box.right > bounds.left + inset(css.borderLeftWidth) + inset(css.paddingLeft)
				&& box.left < bounds.right - inset(css.borderRightWidth) - inset(css.paddingRight);
		});
		const setFlow = inline => {
			document.body.toggleAttribute(flow, inline);
			const media = inline ? 'all' : fallback;
			if (stylesheet.media !== media) stylesheet.media = media;
		};
		const measure = () => {
			if (mobile.matches) {
				setFlow(true);
				return;
			}

			// Read the native floating positions in the same frame, even when the previous result was inline.
			setFlow(false);
			const bell = document.querySelector(bellSelector);
			const icon = bell?.querySelector('.wonderpush-icon-container');
			const discrete = bell?.classList.contains('wonderpush-discrete');
			const transition = icon?.style.transitionProperty;
			if (discrete && icon) {
				icon.style.transitionProperty = 'none';
				bell.classList.remove('wonderpush-discrete');
			}
			const inline = overlaps(peer.getBoundingClientRect(), regions)
				|| (bell && overlaps(bell.getBoundingClientRect(), regions));
			if (discrete && icon) {
				bell.classList.add('wonderpush-discrete');
				icon.style.transitionProperty = transition;
			}
			setFlow(Boolean(inline));
		};
		const observer = new ResizeObserver(measure);
		observer.observe(document.documentElement);
		regions.forEach(region => observer.observe(region));
		mobile.addEventListener('change', measure);
		measure();
		return measure;
	}

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

	if (!document.body) return;
	const measure = watchPlacement();
	const bind = () => {
		document.querySelectorAll(bellSelector).forEach(attach);
		measure();
	};
	new MutationObserver(bind).observe(document.body, { childList: true });
	bind();
})();
