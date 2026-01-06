(function () {
	'use strict';

	if (!window.wp || !wp.element || !wp.components || !wp.apiFetch) {
		return;
	}

	var apiFetch = wp.apiFetch;
	var config = window.agentwpSettings || {};

	if (config.nonce && apiFetch.createNonceMiddleware) {
		apiFetch.use(apiFetch.createNonceMiddleware(config.nonce));
	}

	if (config.root && apiFetch.createRootURLMiddleware) {
		apiFetch.use(apiFetch.createRootURLMiddleware(config.root));
	}

	var el = wp.element.createElement;
	var render = wp.element.render;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;

	var Button = wp.components.Button;
	var Card = wp.components.Card;
	var CardBody = wp.components.CardBody;
	var CardHeader = wp.components.CardHeader;
	var Flex = wp.components.Flex;
	var FlexItem = wp.components.FlexItem;
	var FormFileUpload = wp.components.FormFileUpload;
	var Notice = wp.components.Notice;
	var SelectControl = wp.components.SelectControl;
	var Spinner = wp.components.Spinner;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;

	var DEFAULT_SETTINGS = {
		model: 'gpt-4o-mini',
		hotkey: 'Cmd+K / Ctrl+K',
		theme: 'light',
	};

	var DEFAULT_USAGE = {
		total_commands_month: 0,
		estimated_cost: 0,
		last_sync: '',
	};

	function normalizeSettings(settings) {
		var merged = Object.assign({}, DEFAULT_SETTINGS, settings || {});
		if (merged.theme !== 'dark' && merged.theme !== 'light') {
			merged.theme = DEFAULT_SETTINGS.theme;
		}
		if (!merged.hotkey) {
			merged.hotkey = DEFAULT_SETTINGS.hotkey;
		}
		if (merged.model !== 'gpt-4o' && merged.model !== 'gpt-4o-mini') {
			merged.model = DEFAULT_SETTINGS.model;
		}
		return merged;
	}

	function normalizeUsage(usage) {
		var parsed = Object.assign({}, DEFAULT_USAGE, usage || {});
		parsed.total_commands_month = parseInt(parsed.total_commands_month, 10) || 0;
		parsed.estimated_cost = parseFloat(parsed.estimated_cost) || 0;
		return parsed;
	}

	function formatCurrency(value) {
		if (window.Intl && Intl.NumberFormat) {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: 'USD',
				maximumFractionDigits: 2,
			}).format(value || 0);
		}

		return '$' + (value || 0).toFixed(2);
	}

	function formatDate(value) {
		if (!value) {
			return __('Not synced yet', 'agentwp');
		}

		var date = new Date(value);
		if (Number.isNaN(date.getTime())) {
			return value;
		}

		return date.toLocaleString();
	}

	function getErrorMessage(error, fallback) {
		if (error && error.message) {
			return error.message;
		}

		if (error && error.data && error.data.message) {
			return error.data.message;
		}

		if (error && error.data && error.data.error && error.data.error.message) {
			return error.data.error.message;
		}

		return fallback;
	}

	function SettingsApp() {
		var _useState = useState(DEFAULT_SETTINGS);
		var settings = _useState[0];
		var setSettings = _useState[1];

		var _useState2 = useState(DEFAULT_SETTINGS);
		var savedSettings = _useState2[0];
		var setSavedSettings = _useState2[1];

		var _useState3 = useState('');
		var apiKeyInput = _useState3[0];
		var setApiKeyInput = _useState3[1];

		var _useState4 = useState('');
		var apiKeyLast4 = _useState4[0];
		var setApiKeyLast4 = _useState4[1];

		var _useState5 = useState(false);
		var hasApiKey = _useState5[0];
		var setHasApiKey = _useState5[1];

		var _useState6 = useState(DEFAULT_USAGE);
		var usage = _useState6[0];
		var setUsage = _useState6[1];

		var _useState7 = useState(true);
		var loading = _useState7[0];
		var setLoading = _useState7[1];

		var _useState8 = useState(false);
		var saving = _useState8[0];
		var setSaving = _useState8[1];

		var _useState9 = useState(null);
		var notice = _useState9[0];
		var setNotice = _useState9[1];

		var _useState10 = useState(null);
		var validationNotice = _useState10[0];
		var setValidationNotice = _useState10[1];

		var _useState11 = useState(false);
		var apiKeyBusy = _useState11[0];
		var setApiKeyBusy = _useState11[1];

		var _useState12 = useState(false);
		var usageLoading = _useState12[0];
		var setUsageLoading = _useState12[1];

		useEffect(function () {
			var isMounted = true;

			async function fetchSettings() {
				try {
					var response = await apiFetch({ path: '/agentwp/v1/settings' });
					if (!isMounted) {
						return;
					}

					if (response && response.success) {
						var nextSettings = normalizeSettings(response.data.settings);
						setSettings(nextSettings);
						setSavedSettings(nextSettings);
						setApiKeyLast4(response.data.api_key_last4 || '');
						setHasApiKey(Boolean(response.data.has_api_key));
					} else {
						setNotice({
							status: 'error',
							message: __('Unable to load settings.', 'agentwp'),
						});
					}
				} catch (error) {
					if (!isMounted) {
						return;
					}
					setNotice({
						status: 'error',
						message: getErrorMessage(error, __('Unable to load settings.', 'agentwp')),
					});
				}
			}

			async function fetchUsage() {
				setUsageLoading(true);
				try {
					var response = await apiFetch({ path: '/agentwp/v1/usage?period=month' });
					if (isMounted && response && response.success) {
						setUsage(normalizeUsage(response.data.usage));
					}
				} catch (error) {
					if (isMounted) {
						setNotice({
							status: 'error',
							message: getErrorMessage(error, __('Unable to load usage stats.', 'agentwp')),
						});
					}
				} finally {
					if (isMounted) {
						setUsageLoading(false);
					}
				}
			}

			Promise.all([fetchSettings(), fetchUsage()]).finally(function () {
				if (isMounted) {
					setLoading(false);
				}
			});

			return function () {
				isMounted = false;
			};
		}, []);

		function updateSetting(key, value) {
			setSettings(function (prev) {
				var next = Object.assign({}, prev);
				next[key] = value;
				return next;
			});
		}

		function saveSettings(nextSettings, successMessage) {
			var payload = {
				model: nextSettings.model,
				hotkey: nextSettings.hotkey,
				theme: nextSettings.theme,
			};
			var previous = savedSettings;

			setSettings(nextSettings);
			setSavedSettings(nextSettings);
			setSaving(true);
			setNotice({
				status: 'info',
				message: __('Saving settings...', 'agentwp'),
			});

			apiFetch({
				path: '/agentwp/v1/settings',
				method: 'POST',
				data: payload,
			})
				.then(function (response) {
					if (response && response.success) {
						var saved = normalizeSettings(response.data.settings);
						setSettings(saved);
						setSavedSettings(saved);
						setNotice({
							status: 'success',
							message: successMessage || __('Settings saved.', 'agentwp'),
						});
					} else {
						setSettings(previous);
						setSavedSettings(previous);
						setNotice({
							status: 'error',
							message: response && response.error && response.error.message
								? response.error.message
								: __('Unable to save settings.', 'agentwp'),
						});
					}
				})
				.catch(function (error) {
					setSettings(previous);
					setSavedSettings(previous);
					setNotice({
						status: 'error',
						message: getErrorMessage(error, __('Unable to save settings.', 'agentwp')),
					});
				})
				.finally(function () {
					setSaving(false);
				});
		}

		function handleSave() {
			saveSettings(settings);
		}

		function handleValidateKey() {
			var candidate = apiKeyInput.trim();
			if (!candidate) {
				setValidationNotice({
					status: 'error',
					message: __('Enter an API key to validate.', 'agentwp'),
				});
				return;
			}

			setApiKeyBusy(true);
			setValidationNotice({
				status: 'info',
				message: __('Validating API key...', 'agentwp'),
			});

			var timeoutId;
			var timeoutPromise = new Promise(function (resolve, reject) {
				timeoutId = setTimeout(function () {
					reject(new Error(__('Validation timed out.', 'agentwp')));
				}, 3000);
			});

			Promise.race([
				apiFetch({
					path: '/agentwp/v1/settings/api-key',
					method: 'POST',
					data: { api_key: candidate },
				}),
				timeoutPromise,
			])
				.then(function (response) {
					if (response && response.success) {
						setApiKeyInput('');
						setApiKeyLast4(response.data.last4 || '');
						setHasApiKey(Boolean(response.data.stored));
						setValidationNotice({
							status: 'success',
							message: __('API key validated and saved.', 'agentwp'),
						});
					} else {
						setValidationNotice({
							status: 'error',
							message: response && response.error && response.error.message
								? response.error.message
								: __('API key validation failed.', 'agentwp'),
						});
					}
				})
				.catch(function (error) {
					setValidationNotice({
						status: 'error',
						message: getErrorMessage(error, __('API key validation failed.', 'agentwp')),
					});
				})
				.finally(function () {
					if (timeoutId) {
						clearTimeout(timeoutId);
					}
					setApiKeyBusy(false);
				});
		}

		function handleExport() {
			var exportData = {
				model: settings.model,
				hotkey: settings.hotkey,
				theme: settings.theme,
				dark_mode: settings.theme === 'dark',
			};

			var blob = new Blob([JSON.stringify(exportData, null, 2)], {
				type: 'application/json',
			});
			var url = window.URL.createObjectURL(blob);
			var link = document.createElement('a');
			link.href = url;
			link.download = 'agentwp-settings.json';
			document.body.appendChild(link);
			link.click();
			link.remove();
			window.URL.revokeObjectURL(url);
		}

		function handleImport(event) {
			var file = event.target.files && event.target.files[0];
			if (!file) {
				return;
			}

			var reader = new FileReader();
			reader.onload = function (loadEvent) {
				try {
					var parsed = JSON.parse(loadEvent.target.result);
					var nextSettings = normalizeSettings({
						model: parsed.model || settings.model,
						hotkey: parsed.hotkey || settings.hotkey,
						theme: parsed.theme || (parsed.dark_mode ? 'dark' : settings.theme),
					});

					saveSettings(nextSettings, __('Settings imported and saved.', 'agentwp'));
				} catch (error) {
					setNotice({
						status: 'error',
						message: __('Imported JSON is invalid.', 'agentwp'),
					});
				}
			};
			reader.readAsText(file);
			event.target.value = '';
		}

		function refreshUsage() {
			setUsageLoading(true);
			apiFetch({ path: '/agentwp/v1/usage?period=month' })
				.then(function (response) {
					if (response && response.success) {
						setUsage(normalizeUsage(response.data.usage));
					}
				})
				.catch(function (error) {
					setNotice({
						status: 'error',
						message: getErrorMessage(error, __('Unable to refresh usage stats.', 'agentwp')),
					});
				})
				.finally(function () {
					setUsageLoading(false);
				});
		}

		if (loading) {
			return el(
				'div',
				{ className: 'agentwp-settings__loading' },
				el(Spinner, null),
				el('span', null, __('Loading AgentWP settings...', 'agentwp'))
			);
		}

		var apiKeyHelp = hasApiKey
			? __('Stored key ending in ****', 'agentwp') + apiKeyLast4
			: __('No API key stored.', 'agentwp');

		return el(
			'div',
			{ className: 'agentwp-settings' },
			el(
				'div',
				{ className: 'agentwp-settings__header' },
				el('h1', null, __('AgentWP Settings', 'agentwp')),
				el(
					'div',
					{ className: 'agentwp-settings__actions' },
					el(Button, { isPrimary: true, onClick: handleSave, isBusy: saving, disabled: saving }, __('Save Settings', 'agentwp'))
				)
			),
			notice
				? el(
						Notice,
						{
							status: notice.status,
							isDismissible: true,
							onRemove: function () {
								setNotice(null);
							},
							className: 'agentwp-settings__notice',
						},
						notice.message
				  )
				: null,
			el(
				'div',
				{ className: 'agentwp-settings__grid' },
				el(
					Card,
					{ className: 'agentwp-settings__full' },
					el(CardHeader, null, el('h2', null, __('API Configuration', 'agentwp'))),
					el(
						CardBody,
						null,
						el(
							'div',
							{ className: 'agentwp-settings__api-row' },
							el(TextControl, {
								label: __('OpenAI API Key', 'agentwp'),
								type: 'password',
								value: apiKeyInput,
								onChange: setApiKeyInput,
								placeholder: hasApiKey ? '****' + apiKeyLast4 : '',
								help: apiKeyHelp,
							}),
							el(
								'div',
								{ className: 'agentwp-settings__api-action' },
								el(
									Button,
									{
										isSecondary: true,
										onClick: handleValidateKey,
										isBusy: apiKeyBusy,
										disabled: apiKeyBusy,
									},
									__('Validate Key', 'agentwp')
								)
							)
						),
						validationNotice
							? el(
									Notice,
									{
										status: validationNotice.status,
										isDismissible: true,
										onRemove: function () {
											setValidationNotice(null);
										},
										className: 'agentwp-settings__notice',
									},
									validationNotice.message
							  )
							: null
					)
				),
				el(
					Card,
					null,
					el(CardHeader, null, el('h2', null, __('Model Selection', 'agentwp'))),
					el(
						CardBody,
						null,
						el(SelectControl, {
							label: __('Model', 'agentwp'),
							value: settings.model,
							options: [
								{ label: __('gpt-4o (recommended)', 'agentwp'), value: 'gpt-4o' },
								{ label: __('gpt-4o-mini (budget)', 'agentwp'), value: 'gpt-4o-mini' },
							],
							onChange: function (value) {
								updateSetting('model', value);
							},
							help: __('Choose the default model for AgentWP requests.', 'agentwp'),
						})
					)
				),
				el(
					Card,
					null,
					el(CardHeader, null, el('h2', null, __('Interface Preferences', 'agentwp'))),
					el(
						CardBody,
						null,
						el(TextControl, {
							label: __('Command Deck Hotkey', 'agentwp'),
							value: settings.hotkey,
							onChange: function (value) {
								updateSetting('hotkey', value);
							},
							help: __('Default: Cmd+K / Ctrl+K', 'agentwp'),
						}),
						el(ToggleControl, {
							label: __('Enable dark mode by default', 'agentwp'),
							checked: settings.theme === 'dark',
							onChange: function (value) {
								updateSetting('theme', value ? 'dark' : 'light');
							},
						})
					)
				),
				el(
					Card,
					null,
					el(CardHeader, null, el('h2', null, __('Usage Statistics', 'agentwp'))),
					el(
						CardBody,
						null,
						el(
							'div',
							{ className: 'agentwp-settings__stats' },
							el(
								'div',
								{ className: 'agentwp-settings__stat' },
								el('p', { className: 'agentwp-settings__stat-label' }, __('Total commands this month', 'agentwp')),
								el('p', { className: 'agentwp-settings__stat-value' }, usage.total_commands_month)
							),
							el(
								'div',
								{ className: 'agentwp-settings__stat' },
								el('p', { className: 'agentwp-settings__stat-label' }, __('Estimated cost', 'agentwp')),
								el('p', { className: 'agentwp-settings__stat-value' }, formatCurrency(usage.estimated_cost))
							),
							el(
								'div',
								{ className: 'agentwp-settings__stat' },
								el('p', { className: 'agentwp-settings__stat-label' }, __('Last sync time', 'agentwp')),
								el('p', { className: 'agentwp-settings__stat-value' }, formatDate(usage.last_sync))
							)
						),
						el(
							'div',
							{ className: 'agentwp-settings__usage-actions' },
							el(
								Button,
								{
									isSecondary: true,
									onClick: refreshUsage,
									isBusy: usageLoading,
									disabled: usageLoading,
								},
								__('Refresh usage', 'agentwp')
							)
						)
					)
				),
				el(
					Card,
					{ className: 'agentwp-settings__full' },
					el(CardHeader, null, el('h2', null, __('Export / Import', 'agentwp'))),
					el(
						CardBody,
						null,
						el(
							Flex,
							{ className: 'agentwp-settings__import-export' },
							el(
								FlexItem,
								null,
								el(Button, { isSecondary: true, onClick: handleExport }, __('Export Settings', 'agentwp'))
							),
							el(
								FlexItem,
								null,
								el(FormFileUpload, {
									accept: 'application/json',
									onChange: handleImport,
									isSecondary: true,
								}, __('Import Settings', 'agentwp'))
							)
						),
						el(
							'p',
							{ className: 'agentwp-settings__hint' },
							__('API keys are not included in exports.', 'agentwp')
						)
					)
				)
			)
		);
	}

	function boot() {
		var root = document.getElementById('agentwp-admin-root');
		if (!root || root.dataset.agentwpInitialized) {
			return;
		}

		render(el(SettingsApp), root);
		root.dataset.agentwpInitialized = 'true';
	}

	document.addEventListener('DOMContentLoaded', boot);
})();
