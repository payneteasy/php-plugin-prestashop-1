{**
 * @author    Payneteasy
 * @copyright 2007-2026 Payneteasy
 * @license   Property of Payneteasy
 *}

	<div class="text-xs-center">
			<h3 id="ticker">{l s='You will be redirected to transaction confirmation page' mod='payneteasypayment'}</h3>
	</div>
	<script>
		let t_el = document.getElementById("ticker")
		let t_s = t_el.innerHTML.trim()
		let t_pos = 1
		setInterval(ticker, 100)
		function ticker() {
			t_el.innerHTML = "<span style=\'text-decoration:underline\'>" + t_s.slice(0, t_pos) + '</span>' + t_s.slice(t_pos)
			if (++t_pos == t_s.length + 1) location.reload() }
	</script>

