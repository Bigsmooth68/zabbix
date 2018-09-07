/*
 ** Zabbix
 ** Copyright (C) 2001-2018 Zabbix SIA
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
 * JQuery class that adds interactivity of vertical accordion for CList element.
 */
jQuery(function ($) {
	"use strict";

	var methods = {
		/**
		 * Create CList based accordion.
		 *
		 * Supported options:
		 * - handler		- selector of UI element to open/close accordion section.
		 * - section		- selector of UI element for single accordion section.
		 * - body			- selector of UI element that should be opened/closed.
		 * - active_class	- CSS class that will be applied for active section.
		 * - closed_class	- CSS class that will be applied for closed section.
		 *
		 * @param options
		 */
		init: function(options) {
			options = $.extend({}, {
				handler: '.list-accordion-item-head',
				section: '.list-accordion-item',
				active_class: 'list-accordion-item-opened',
				closed_class: 'list-accordion-item-closed',
				body: '.list-accordion-item-body'
			}, options);

			this.each(function() {
				var accordion = $(this);

				// Bind collapse/expend.
				accordion
					.data('options', options)
					.on('click', options['handler'], function() {
						var section = $(this).closest(options['section']),
							button = $(options['handler'], section);

						if (section.hasClass(options['active_class'])) {
							button.attr('title', t('S_EXPAND'));
							section
								.removeClass(options['active_class'])
								.addClass(options['closed_class']);
						}
						else {
							methods['collapseAll'].apply(accordion);
							button.attr('title', t('S_COLLAPSE'));
							section
								.removeClass(options['closed_class'])
								.addClass(options['active_class']);
						}
					});
			});
		},
		// Collapse all accordion rows.
		collapseAll: function() {
			var accordion = $(this),
				options = accordion.data('options'),
				active_class = accordion.data('options')['active_class'],
				closed_class = accordion.data('options')['closed_class'];
			$('.'+active_class, accordion)
				.removeClass(active_class)
				.addClass(closed_class);

			$(options['handler'], accordion).attr('title', t('S_EXPAND'));
		},
		// Expand N-th row in accordion. Collapse others.
		expandNth: function(n) {
			var accordion = $(this),
				options = accordion.data('options');

			$(options['handler'], $('.'+options['active_class'], accordion)).attr('title', t('S_EXPAND'));
			$(options['handler'], $(options['section']+':nth('+n+')', accordion)).attr('title', t('S_COLLAPSE'));

			$('.'+options['active_class'], accordion)
				.removeClass(options['active_class'])
				.addClass(options['closed_class']);
			$(options['section']+':nth('+n+')', accordion)
				.removeClass(options['closed_class'])
				.addClass(options['active_class']);
		}
	};

	$.fn.zbx_vertical_accordion = function(method) {
		if (methods[method]) {
			return methods[method].apply(this, Array.prototype.slice.call(arguments, 1));
		}
		else {
			return methods.init.apply(this, arguments);
		}
	};
});
