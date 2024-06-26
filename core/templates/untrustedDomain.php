<?php
/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2014-2015 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
/** @var array $_ */
?>

<div class="guest-box">
	<h2><?php p($l->t('Access through untrusted domain')); ?></h2>

	<p>
		<?php p($l->t('Please contact your administrator. If you are an administrator, edit the "trusted_domains" setting in config/config.php like the example in config.sample.php.')); ?>
	</p>
	<br />
	<p>
		<?php print_unescaped($l->t('Further information how to configure this can be found in the %1$sdocumentation%2$s.', ['<a href="' . $_['docUrl'] . '" target="blank">', '</a>'])); ?>
	</p>
</div>
