(($) => {
	'use strict'

	var { field_prefix = '', row_selector = 'tr' } = window.pneAdminSettings

	$(() => {
		var fields = [], rows = { LIVE:[], SANDBOX:[] }
		'URL END_POINT LOGIN CONTROL_KEY'.split(' ').forEach((k) => {
			['LIVE','SANDBOX'].forEach((section) => {
				var $field = $('#'+field_prefix+section+'_'+k)
				fields.push($field[0])

				rows[section].push($field.closest(row_selector)[0])

				var $other = $('#'+field_prefix+(section == 'LIVE' ? 'SANDBOX' : 'LIVE')+'_'+k)
				$field.on('blur', () => { '' == $other.val() ? $other.val($field.val()) : 0 })
			})
		})

		var $fields = $(fields), $rows = { LIVE:$(rows.LIVE), SANDBOX:$(rows.SANDBOX) }

		var checks = {
			URL: /^https?:\/\/(?:\w+(?:-\w+)*\.)+\w+\/$/,
			END_POINT: /^\d+$/,
			LOGIN: /^[a-z][\w-]*\w$/i,
			CONTROL_KEY: /^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/i }

		function validateField($f) {
			var valid = checks[ $f.attr('id').substr(field_prefix.length).replace(/LIVE_|SANDBOX_/, '') ].test($f.val())

			$f.toggleClass('pne-invalid', !valid).css('background-color', valid ? '' : '#FDD')

			return valid
		}

		$rows.LIVE.add($rows.SANDBOX).css('display', 'none')

		var $isLive = $('#'+field_prefix+'IS_LIVE'), $isMulticurr = $('#'+field_prefix+'IS_MULTICURR')
		function toggle_isLive(duration=0) {
			var is_live = $isLive.is(':checked')
			$('#pne_is_live_desc_on').toggle(is_live)
			$('#pne_is_live_desc_off').toggle(!is_live)

			var [on, off] = is_live ? ['LIVE','SANDBOX'] : ['SANDBOX','LIVE']
			$rows[off].stop(true, true).fadeOut(duration, () => $rows[on].stop(true, true).fadeIn(duration))
		}

		function toggle_isMulticurr() {
			var is_multi = $isMulticurr.is(':checked')
			var $off = $('.pne_is_multi_off'), $on = $('.pne_is_multi_on'), $blink = $('.pne_is_multi')

			$off.toggle(!is_multi)
			$on.toggle(is_multi)

			$blink.stop(true, true).fadeOut(200, () => $blink.stop(true, true).fadeIn(200))

			var [from,to] = is_multi ? ['Endpoint ID','Endpoint Group ID'] : ['Endpoint Group ID','Endpoint ID']
			$('.pne_endpointid_label').text((i, s) => s.replace(from, to))
		}

		toggle_isLive()

		$isLive.on('change', () => toggle_isLive(200))
		$isMulticurr.on('change', toggle_isMulticurr)

		$fields.on('input blur', (e) => validateField($(e.target).first()))

		$fields.closest('form').on('submit', (event) => {
			var $invalid = $($.grep($fields, (f, i) => validateField($(f)), true)).first()

			if ($invalid.length) {
				event.preventDefault()
				$invalid.trigger('focus')
				$invalid.get(0).scrollIntoView({ behavior: 'smooth', block: 'center' })
			}
		})
	})
})(jQuery)
