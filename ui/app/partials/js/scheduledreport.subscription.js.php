<?php
/*
** Zabbix
** Copyright (C) 2001-2021 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * @var CPartial $this
 */
?>

<script>
	var row_num = 0;
	var userids = new Set();
	var usrgrpids = new Set();

	var ReportSubscription = class {

		constructor(data, edit = null) {
			this.data = data;

			this.row = document.createElement('tr');
			this.row.appendChild(this.createRecipientCell());
			this.row.appendChild(this.createCreatorCell());
			this.row.appendChild(this.createStatusCell());
			this.row.appendChild(this.createActionCell());

			this.render(edit);
			row_num++;
		}

		render(edit) {
			if (edit instanceof Element) {
				if (this.data.recipientid != this.data.old_recipientid) {
					if (this.data.recipient_type == <?= ZBX_REPORT_RECIPIENT_TYPE_USER ?>) {
						userids
							.add(this.data.recipientid)
							.delete(this.data.old_recipientid);
					}
					else {
						usrgrpids
							.add(this.data.recipientid)
							.delete(this.data.old_recipientid);
					}
				}

				return edit.replaceWith(this.row);
			}

			return document
				.querySelector('#subscriptions-table tbody')
				.append(this.row);
		}

		createRecipientCell() {
			const cell = document.createElement('td');
			const icon = document.createElement('span');
			const link = document.createElement('a');

			if (this.data.recipient_type == <?= ZBX_REPORT_RECIPIENT_TYPE_USER ?>) {
				icon.classList.add('<?= ZBX_STYLE_ICON_USER ?>');
				icon.setAttribute('title', <?= json_encode(_('User')) ?>);
				userids.add(this.data.recipientid);
			}
			else {
				icon.classList.add('<?= ZBX_STYLE_ICON_USER_GROUP ?>');
				icon.setAttribute('title', <?= json_encode(_('User group')) ?>);
				usrgrpids.add(this.data.recipientid);
			}

			link.innerHTML = this.data.recipient_name;
			link.href = 'javascript:void(0);';
			link.setAttribute('title', this.data.recipient_name);
			link.addEventListener('click', (event) => {
				const popup_options = Object.assign(this.data, {
					edit: 1,
					old_recipientid: this.data.recipientid,
					userids: Array.from(userids),
					usrgrpids: Array.from(usrgrpids)
				});

				if (this.data.recipient_type == <?= ZBX_REPORT_RECIPIENT_TYPE_USER ?>) {
					popup_options.exclude = link.parentNode.parentNode.querySelector('[name*=exclude]').value;
				}

				PopUp('popup.scheduledreport.subscription.edit', popup_options, null, event.target);
			});

			cell.appendChild(icon);
			cell.appendChild(link);
			cell.appendChild(this.createHiddenInput('[recipientid]', this.data.recipientid));
			cell.appendChild(this.createHiddenInput('[recipient_type]', this.data.recipient_type));
			cell.appendChild(this.createHiddenInput('[recipient_name]', this.data.recipient_name));

			return cell;
		}

		createCreatorCell() {
			const cell = document.createElement('td');
			const span = document.createElement('span');

			if (this.data.creator_type == <?= ZBX_REPORT_CREATOR_TYPE_USER ?>) {
				const creator_name = <?= json_encode(getUserFullname(CWebUser::$data)) ?>;

				span.innerHTML = creator_name;
				span.setAttribute('title', creator_name);
			}
			else {
				span.innerHTML = <?= json_encode(_('Recipient')) ?>;
				span.classList.add('<?= ZBX_STYLE_GREY ?>');
			}

			cell.appendChild(span);
			cell.appendChild(this.createHiddenInput('[creator_type]', this.data.creator_type));

			return cell;
		}

		createStatusCell() {
			const cell = document.createElement('td');

			if (this.data.recipient_type == <?= ZBX_REPORT_RECIPIENT_TYPE_USER_GROUP ?>) {
				return cell;
			}

			const link = document.createElement('a');

			link.href = 'javascript:void(0);';
			link.classList.add('<?= ZBX_STYLE_LINK_ACTION ?>');

			if (this.data.exclude == <?= ZBX_REPORT_EXCLUDE_USER_FALSE ?>) {
				link.innerHTML = <?= json_encode(_('Include')) ?>;
				link.classList.add('<?= ZBX_STYLE_GREEN ?>');
			}
			else {
				link.innerHTML = <?= json_encode(_('Exclude')) ?>;
				link.classList.add('<?= ZBX_STYLE_RED ?>');
			}

			link.addEventListener('click', (event) => {
				const input = link.parentNode.querySelector('[name*=exclude]');

				if (input.value == <?= ZBX_REPORT_EXCLUDE_USER_TRUE ?>) {
					link.innerHTML = <?= json_encode(_('Include')) ?>;
					link.classList.replace('<?= ZBX_STYLE_RED ?>', '<?= ZBX_STYLE_GREEN ?>');
					input.value = <?= ZBX_REPORT_EXCLUDE_USER_FALSE ?>
				}
				else {
					link.innerHTML = <?= json_encode(_('Exclude')) ?>;
					link.classList.replace('<?= ZBX_STYLE_GREEN ?>', '<?= ZBX_STYLE_RED ?>');
					input.value = <?= ZBX_REPORT_EXCLUDE_USER_TRUE ?>
				}
			});

			cell.appendChild(link);
			cell.appendChild(this.createHiddenInput('[exclude]', this.data.exclude));

			return cell;
		}

		createActionCell() {
			const cell = document.createElement('td');
			const btn = document.createElement('button');

			btn.type = 'button';
			btn.classList.add('<?= ZBX_STYLE_BTN_LINK ?>');
			btn.innerHTML = <?= json_encode(_('Remove')) ?>;
			btn.addEventListener('click', () => {
				if (this.data.recipient_type == <?= ZBX_REPORT_RECIPIENT_TYPE_USER ?>) {
					userids.delete(this.data.recipientid);
				}
				else {
					usrgrpids.delete(this.data.recipientid);
				}

				this.row.remove();
			});

			cell.appendChild(btn);

			return cell;
		}

		createHiddenInput(name, value) {
			const input = document.createElement('input');

			input.type = 'hidden';
			input.name = `subscriptions[${row_num}]${name}`;
			input.value = value;

			return input;
		}

		static initializeNewUserPopup() {
			const elem = document.querySelector('#subscriptions-table .js-add-user:not(:disabled)');

			if (!elem) {
				return;
			}

			elem.addEventListener('click', (event) => {
				const popup_options = {
					recipient_type: <?= ZBX_REPORT_RECIPIENT_TYPE_USER ?>,
					userids: Array.from(userids)
				};

				PopUp('popup.scheduledreport.subscription.edit', popup_options, null, event.target);
			});
		}

		static initializeNewUserGroupPopup() {
			const elem = document.querySelector('#subscriptions-table .js-add-user-group:not(:disabled)');

			if (!elem) {
				return;
			}

			elem.addEventListener('click', (event) => {
				const popup_options = {
					recipient_type: <?= ZBX_REPORT_RECIPIENT_TYPE_USER_GROUP ?>,
					usrgrpids: Array.from(usrgrpids)
				};

				PopUp('popup.scheduledreport.subscription.edit', popup_options, null, event.target);
			});
		}
	}

	var subscriptions = <?= json_encode(array_values($data['subscriptions'])) ?>;

	subscriptions.forEach((subscription) => new ReportSubscription(subscription));

	ReportSubscription.initializeNewUserPopup();
	ReportSubscription.initializeNewUserGroupPopup();
</script>
