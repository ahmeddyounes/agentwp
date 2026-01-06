(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.getElementById('agentwp-admin-root');
		if (root && ! root.dataset.agentwpInitialized) {
			root.textContent = 'AgentWP admin UI will render here.';
			root.dataset.agentwpInitialized = 'true';
		}
	});
})();
