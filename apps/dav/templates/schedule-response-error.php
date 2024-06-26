<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
?>
<div class="guest-box">
	<div class="notecard error">
		<p><?php p($l->t('There was an error updating your attendance status.'));?></p>
		<p><?php p($l->t('Please contact the organizer directly.'));?></p>
		<?php if (isset($_['organizer'])): ?>
			<p><a href="<?php p($_['organizer']) ?>"><?php p(substr($_['organizer'], 7)) ?></a></p>
		<?php endif; ?>
	</div>
</div>
