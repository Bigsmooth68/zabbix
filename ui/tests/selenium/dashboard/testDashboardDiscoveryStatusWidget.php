<?php
/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
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


require_once dirname(__FILE__) . '/../../include/CWebTest.php';
require_once dirname(__FILE__).'/../../include/helpers/CDataHelper.php';
require_once dirname(__FILE__).'/../behaviors/CMessageBehavior.php';
require_once dirname(__FILE__).'/../behaviors/CTableBehavior.php';

/**
 * @backup widget
 *
 * @onBefore prepareDiscoveryStatusWidgetData
 *
 * @dataSource Proxies
 */

class testDashboardDiscoveryStatusWidget extends CWebTest {

	/**
	 * Attach MessageBehavior and TableBehavior to the test.
	 *
	 * @return array
	 */
	public function getBehaviors() {
		return [
			CMessageBehavior::class,
			CTableBehavior::class
		];
	}

	/**
	 * Id of the dashboard with widgets.
	 *
	 * @var integer
	 */

	protected static $dashboardid;
	protected static $update_widget = 'Update widget';
	const DISCOVERY_RULE_1 = 'A discovery rule to be displayed first';
	const DISCOVERY_RULE_2 = 'Discovery rule with 0 discovered hosts';
	const DISCOVERY_RULE_3 = 'Discovery rule with active discovered hosts';
	const DISCOVERY_RULE_4 = 'Discovery rule with both type of hosts hosts';
	const DISCOVERY_RULE_5 = 'XYZ - discovery rule to be displayed last (Inactive hosts)';
	const DELETE_WIDGET = 'Widget to delete';
	const DATA_WIDGET = 'Widget for data check';
	const CANCEL_WIDGET = 'Widget for testing cancel button';

	/**
	 * SQL query to get widget and widget_field tables to compare hash values, but without widget_fieldid
	 * because it can change.
	 */
	const SQL = 'SELECT wf.widgetid, wf.type, wf.name, wf.value_int, wf.value_str, wf.value_groupid, wf.value_hostid,'.
			' wf.value_itemid, wf.value_graphid, wf.value_sysmapid, w.widgetid, w.dashboard_pageid, w.type, w.name, w.x, w.y,'.
			' w.width, w.height'.
			' FROM widget_field wf'.
			' INNER JOIN widget w'.
			' ON w.widgetid=wf.widgetid '.
			' ORDER BY wf.widgetid, wf.name, wf.value_int, wf.value_str, wf.value_groupid, wf.value_itemid, wf.value_graphid';

	/**
	 * Create data for autotests which use DiscoveryStatusWidget.
	 *
	 * @return array
	 */
	public static function prepareDiscoveryStatusWidgetData() {

		CDataHelper::call('drule.create', [
			[
				'name' => self::DISCOVERY_RULE_1,
				'iprange' => '192.168.1.1-255',
				'dchecks' => [
					[
						'type' => 4		// Check: HTTP
					]
				]
			],
			[
				'name' => self::DISCOVERY_RULE_2,
				'iprange' => '192.168.1.1-255',
				'dchecks' => [
					[
						'type' => 4		// Check: HTTP
					]
				]
			],
			[
				'name' => self::DISCOVERY_RULE_3,
				'iprange' => '192.168.1.1-255',
				'dchecks' => [
					[
						'type' => 4		// Check: HTTP
					]
				]
			],
			[
				'name' => self::DISCOVERY_RULE_4,
				'iprange' => '192.168.1.1-255',
				'dchecks' => [
					[
						'type' => 4		// Check: HTTP
					]
				]
			],
			[
				'name' => self::DISCOVERY_RULE_5,
				'iprange' => '192.168.1.1-255',
				'dchecks' => [
					[
						'type' => 4		// Check: HTTP
					]
				]
			]
		]);

		CDataHelper::call('dashboard.create', [
			[
				'name' => 'Dashboard for testing layout of discovery status widget',
				'display_period' => 60,
				'auto_start' => 0,
				'pages' => [
					[
						'name' => 'First page'
					]
				]
			],
			[
				'name' => 'Dashboard for testing actions with discovery status widget',
				'display_period' => 60,
				'auto_start' => 0,
				'pages' => [
					[
						'name' => 'First page',
						'widgets' => [
							[
								'type' => 'discovery',
								'name' => self::$update_widget,
								'x' => 0,
								'y' => 0,
								'width' => 12,
								'height' => 4
							],
							[
								'type' => 'discovery',
								'name' => self::DELETE_WIDGET,
								'x' => 12,
								'y' => 0,
								'width' => 8,
								'height' => 4
							]
						]
					]
				]
			],
			[
				'name' => 'Dashboard for testing cancel button for discovery status widget',
				'display_period' => 60,
				'auto_start' => 0,
				'pages' => [
					[
						'name' => 'First page',
						'widgets' => [
							[
								'type' => 'discovery',
								'name' => self::CANCEL_WIDGET,
								'x' => 0,
								'y' => 0,
								'width' => 12,
								'height' => 5
							]
						]
					]
				]
			],
			[
				'name' => 'Dashboard for testing widgets table data',
				'display_period' => 60,
				'auto_start' => 0,
				'pages' => [
					[
						'name' => 'First page',
						'widgets' => [
							[
								'type' => 'discovery',
								'name' => self::DATA_WIDGET,
								'x' => 0,
								'y' => 0,
								'width' => 12,
								'height' => 5
							]
						]
					]
				]
			]
		]);

		self::$dashboardid = CDataHelper::getIds('name');
	}

	/**
	 * Check discovery status widget layout.
	 */
	public function testDashboardDiscoveryStatusWidget_Layout() {
		$this->page->login()->open('zabbix.php?action=dashboard.view&dashboardid='.
				self::$dashboardid['Dashboard for testing layout of discovery status widget']);
		$dialog = CDashboardElement::find()->one()->edit()->addWidget();
		$form = $dialog->asForm();
		$this->assertEquals('Add widget', $dialog->getTitle());
		$form->fill(['Type' => CFormElement::RELOADABLE_FILL('Discovery status')]);

		$form->checkValue([
			'Name' => '',
			'Refresh interval' => 'Default (1 minute)',
			'id:show_header' => true
		]);

		$this->assertEquals(255, $form->getField('Name')->getAttribute('maxlength'));
		$this->assertEquals('default', $form->getField('Name')->getAttribute('placeholder'));

		$this->assertEquals($form->getField('Refresh interval')->asDropdown()->getOptions()->asText(), [
				'Default (1 minute)',
				'No refresh',
				'10 seconds',
				'30 seconds',
				'1 minute',
				'2 minutes',
				'10 minutes',
				'15 minutes'
		]);

		// Check that close button is present and clickable
		$this->assertTrue($dialog->query('class:btn-overlay-close')->one()->isClickable());

		// Check if footer buttons are present and clickable.
		$this->assertEquals(['Add', 'Cancel'], $dialog->getFooter()->query('button')->all()
				->filter(CElementFilter::CLICKABLE)->asText()
		);
	}

	public static function getDiscoveryStatusWidgetData() {
		return [
			// #0 With default name.
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Name' => '',
						'Show header' => true,
						'Refresh interval' => 'Default (1 minute)'
					]
				]
			],
			// #1 Name with special symbols.
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Name' => 'Test ⭐㖵㖶 🙃 㓈㓋',
						'Show header' => true,
						'Refresh interval' => 'No refresh'
					]
				]
			],
			// #2 Name with leading spaces.
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Name' => '  Trimmed name 1',
						'Show header' => true,
						'Refresh interval' => '10 seconds'
					],
					'trim' => true
				]
			],
			// #3 Name with trailing spaces.
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Name' => 'Trimmed name 2   ',
						'Show header' => true,
						'Refresh interval' => '30 seconds'
					],
					'trim' => true
				]
			],
			// #4 Name with leading and trailing spaces.
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Name' => ' Name with leading and trailing spaces ',
						'Show header' => true,
						'Refresh interval' => '1 minute'
					],
					'trim' => true
				]
			],
			// #5 No visible header.
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Name' => 'No visible header',
						'Show header' => false,
						'Refresh interval' => '2 minutes'
					]
				]
			],
			// #6 10 minutes refresh interval.
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Name' => '10 minutes update interval',
						'Show header' => true,
						'Refresh interval' => '10 minutes'
					]
				]
			],
			// #7 Maximum refresh interval.
			[
				[
					'expected' => TEST_GOOD,
					'fields' => [
						'Name' => 'Max update interval',
						'Show header' => true,
						'Refresh interval' => '15 minutes'
					]
				]
			]
		];
	}

	/**
	 * @dataProvider getDiscoveryStatusWidgetData
	 */
	public function testDashboardDiscoveryStatusWidget_Create($data) {
		$this->checkWidgetForm($data);
	}

	/**
	 * @dataProvider getDiscoveryStatusWidgetData
	 */
	public function testDashboardDiscoveryStatusWidget_Update($data) {
		$this->checkWidgetForm($data, true);
	}

	public function testDashboardDiscoveryStatusWidget_SimpleUpdate() {
		$old_hash = CDBHelper::getHash(self::SQL);
		$this->page->login()->open('zabbix.php?action=dashboard.view&dashboardid='.
				self::$dashboardid['Dashboard for testing actions with discovery status widget'])->waitUntilReady();
		$dashboard = CDashboardElement::find()->one();
		$dashboard->getWidget(self::$update_widget)->edit()->submit();
		$dashboard->save();
		$this->page->waitUntilReady();
		$this->assertMessage(TEST_GOOD, 'Dashboard updated');
		$this->assertEquals($old_hash, CDBHelper::getHash(self::SQL));
	}

	public function testDashboardDiscoveryStatusWidget_Delete() {
		$this->page->login()->open('zabbix.php?action=dashboard.view&dashboardid='.
				self::$dashboardid['Dashboard for testing actions with discovery status widget'])->waitUntilReady();
		$dashboard = CDashboardElement::find()->one()->edit();
		$widget = $dashboard->getWidget(self::DELETE_WIDGET);
		$dashboard->deleteWidget(self::DELETE_WIDGET);
		$widget->waitUntilNotPresent();
		$dashboard->save();
		$this->assertMessage(TEST_GOOD, 'Dashboard updated');

		// Check that widget is not present on dashboard.
		$this->assertFalse($dashboard->getWidget(self::DELETE_WIDGET, false)->isValid());
		$this->assertEquals(0, CDBHelper::getCount('SELECT * FROM widget_field wf'.
				' LEFT JOIN widget w'.
					' ON w.widgetid=wf.widgetid'.
					' WHERE w.name='.zbx_dbstr(self::DELETE_WIDGET)
		));
	}

	public static function getCancelData() {
		return [
			// Cancel update widget.
			[
				[
					'update' => true,
					'save_widget' => true,
					'save_dashboard' => false
				]
			],
			[
				[
					'update' => true,
					'save_widget' => false,
					'save_dashboard' => true
				]
			],
			// Cancel create widget.
			[
				[
					'save_widget' => true,
					'save_dashboard' => false
				]
			],
			[
				[
					'save_widget' => false,
					'save_dashboard' => true
				]
			]
		];
	}

	/**
	 * @dataProvider getCancelData
	 */
	public function testDashboardDiscoveryStatusWidget_Cancel($data) {
		$old_hash = CDBHelper::getHash(self::SQL);
		$new_name = 'Cancel test';

		$this->page->login()->open('zabbix.php?action=dashboard.view&dashboardid='.
				self::$dashboardid['Dashboard for testing cancel button for discovery status widget'])->waitUntilReady();
		$dashboard = CDashboardElement::find()->one()->edit();
		$old_widget_count = $dashboard->getWidgets()->count();

		// Start updating or creating a widget.
		if (CTestArrayHelper::get($data, 'update', false)) {
			$form = $dashboard->getWidget(self::CANCEL_WIDGET)->edit();
		}
		else {
			$form = $dashboard->addWidget()->asForm();
			$form->fill(['Type' => CFormElement::RELOADABLE_FILL('Discovery status')]);
		}

		$form->fill([
			'Name' => $new_name,
			'Refresh interval' => '15 minutes'
		]);

		// Save or cancel widget.
		if (CTestArrayHelper::get($data, 'save_widget', false)) {
			$form->submit();

			// Check that changes took place on the unsaved dashboard.
			$this->assertTrue($dashboard->getWidget($new_name)->isVisible());
		}
		else {
			$dialog = COverlayDialogElement::find()->one();
			$dialog->query('button:Cancel')->one()->click();
			$dialog->ensureNotPresent();

			if (CTestArrayHelper::get($data, 'update', false)) {
				foreach ([self::CANCEL_WIDGET => true, $new_name => false] as $name => $valid) {
					$dashboard->getWidget($name, false)->isValid($valid);
				}
			}

			$this->assertEquals($old_widget_count, $dashboard->getWidgets()->count());
		}

		// Save or cancel dashboard update.
		if (CTestArrayHelper::get($data, 'save_dashboard', false)) {
			$dashboard->save();
		}
		else {
			$dashboard->cancelEditing();
		}

		$this->assertEquals($old_hash, CDBHelper::getHash(self::SQL));
	}

	public static function getWidgetTableData() {
		return [
			[
				[
					[
						'Discovery rule' => self::DISCOVERY_RULE_1,
						'Up' => '',
						'Down' => ''
					],
					[
						'Discovery rule' => 'Discovery rule for proxy delete test',
						'Up' => '',
						'Down' => ''
					],
					[
						'Discovery rule' => self::DISCOVERY_RULE_2,
						'Up' => '',
						'Down' => ''
					],
					[
						'Discovery rule' => self::DISCOVERY_RULE_3,
						'Up' => '100',
						'Down' => ''
					],
					[
						'Discovery rule' => self::DISCOVERY_RULE_4,
						'Up' => '100',
						'Down' => '100'
					],
					[
						'Discovery rule' => self::DISCOVERY_RULE_5,
						'Up' => '',
						'Down' => '100'
					]
				]
			]
		];
	}

	/**
	 * @dataProvider getWidgetTableData
	 */
	public function testDashboardDiscoveryStatusWidget_checkWidgetTableData($data) {

		// Get ID's of the discovery rules from database.
		$drule_id_1 = CDBHelper::getValue('SELECT druleid FROM drules WHERE name='.zbx_dbstr(self::DISCOVERY_RULE_1));
		$drule_id_2 = CDBHelper::getValue('SELECT druleid FROM drules WHERE name='.zbx_dbstr(self::DISCOVERY_RULE_2));
		$drule_id_3 = CDBHelper::getValue('SELECT druleid FROM drules WHERE name='.zbx_dbstr(self::DISCOVERY_RULE_3));
		$drule_id_4 = CDBHelper::getValue('SELECT druleid FROM drules WHERE name='.zbx_dbstr(self::DISCOVERY_RULE_4));
		$drule_id_5 = CDBHelper::getValue('SELECT druleid FROM drules WHERE name='.zbx_dbstr(self::DISCOVERY_RULE_5));
		$drule_id_6 = CDBHelper::getValue('SELECT druleid FROM drules WHERE name='.zbx_dbstr('Discovery rule for proxy delete test'));

		// Insert data into the database (dhosts table), to imitate the host discovery.
		for ($i = 1, $j = 101; $i + $j <= 301; $i++, $j++) {
			DBexecute("INSERT INTO dhosts (dhostid, druleid, status) VALUES (".$j.", ".$drule_id_3.", 0)");
			DBexecute("INSERT INTO dhosts (dhostid, druleid, status) VALUES (".$i.", ".$drule_id_5.", 1)");
		}

		for ($i = 201, $j = 302; $i + $j <= 702; $i++, $j++) {
			DBexecute("INSERT INTO dhosts (dhostid, druleid, status) VALUES (".$j.", ".$drule_id_4.", 0)");
			DBexecute("INSERT INTO dhosts (dhostid, druleid, status) VALUES (".$i.", ".$drule_id_4.", 1)");
		}

		$this->page->login()->open('zabbix.php?action=dashboard.view&dashboardid='.
				self::$dashboardid['Dashboard for testing widgets table data']);
		$dashboard = CDashboardElement::find()->one();
		$widget_data = $dashboard->getWidget(self::DATA_WIDGET)->getContent()->asTable();

		// Check the table content.
		$this->assertEquals($widget_data->getHeadersText(), ['Discovery rule', 'Up', 'Down']);
		$this->assertTableData($data);

		// Check that amount of data with classes green / red is correct in the widget table.
		$this->assertEquals(2, $widget_data->query('class:red')->all()->count());
		$this->assertEquals(2, $widget_data->query('class:green')->all()->count());

		$drules = [
			[$drule_id_1, $drule_id_2, $drule_id_3, $drule_id_4, $drule_id_5, $drule_id_6],
			[self::DISCOVERY_RULE_1, self::DISCOVERY_RULE_2, self::DISCOVERY_RULE_3, self::DISCOVERY_RULE_4,
				self::DISCOVERY_RULE_5, 'Discovery rule for proxy delete test']
		];

		// Check the links of the discovery rules.
		for ($i = 0; $i <= 5; $i++) {
			$this->assertEquals('zabbix.php?action=discovery.view&filter_set=1&filter_druleids%5B0%5D='.$drules[0][$i],
					$widget_data->query('link', $drules[1][$i])->one()->getAttribute('href')
			);
		}
	}

	public function testDashboardDiscoveryStatusWidget_checkEmptyWidget() {

		// Disable discovery rules in the database to check the content of the empty widget.
		DBexecute('UPDATE drules SET status=1 WHERE name IN ('.
				zbx_dbstr(self::DISCOVERY_RULE_1).', '.zbx_dbstr(self::DISCOVERY_RULE_2).
				', '.zbx_dbstr(self::DISCOVERY_RULE_3).', '.zbx_dbstr(self::DISCOVERY_RULE_4).
				', '.zbx_dbstr(self::DISCOVERY_RULE_5).', '.zbx_dbstr('Discovery rule for proxy delete test').')'
		);

		$this->page->login()->open('zabbix.php?action=dashboard.view&dashboardid='.
				self::$dashboardid['Dashboard for testing widgets table data']);
		$dashboard = CDashboardElement::find()->one();
		$widget_data = $dashboard->getWidget(self::DATA_WIDGET)->getContent()->asTable();

		// Check the table content.
		$this->assertEquals($widget_data->getHeadersText(), ['Discovery rule', 'Up', 'Down']);
		$this->assertEquals('No data found.', $widget_data->query('class:nothing-to-show')->one()->getText());

		// Enable back the rule for proxy test.
		DBexecute('UPDATE drules SET status=0 WHERE name='.zbx_dbstr('Discovery rule for proxy delete test'));
	}

	protected function checkWidgetForm($data, $update = false) {
		$expected = CTestArrayHelper::get($data, 'expected', TEST_GOOD);

		$this->page->login()->open('zabbix.php?action=dashboard.view&dashboardid='.
				self::$dashboardid['Dashboard for testing actions with discovery status widget']);
		$dashboard = CDashboardElement::find()->one();
		$old_widget_count = $dashboard->getWidgets()->count();

		$form = ($update)
			? $dashboard->getWidget(self::$update_widget)->edit()->asForm()
			: $dashboard->edit()->addWidget()->asForm();

		$form->fill(['Type' => CFormElement::RELOADABLE_FILL('Discovery status')]);
		$form->fill($data['fields']);

		if ($expected === TEST_GOOD) {
			$values = $form->getFields()->filter(CElementFilter::VISIBLE)->asValues();
		}

		$form->submit();
		$this->page->waitUntilReady();

		// Trim leading and trailing spaces from expected results if necessary.
		if (array_key_exists('trim', $data)) {
			$data['fields']['Name'] = trim($data['fields']['Name']);
		}
		// If name is empty string it is replaced by default name "Discovery status".
		$header = ($data['fields']['Name'] === '') ? 'Discovery status' : $data['fields']['Name'];

		if ($update) {
			self::$update_widget = $header;
		}

		COverlayDialogElement::ensureNotPresent();
		$widget = $dashboard->getWidget($header);

		// Save Dashboard to ensure that widget is correctly saved.
		$dashboard->save();
		$this->page->waitUntilReady();
		$this->assertMessage(TEST_GOOD, 'Dashboard updated');

		// Check widgets count.
		$this->assertEquals($old_widget_count + ($update ? 0 : 1), $dashboard->getWidgets()->count());

		// Check new widget form fields and values in frontend.
		$saved_form = $widget->edit();
		$this->assertEquals($values, $saved_form->getFields()->filter(CElementFilter::VISIBLE)->asValues());
		$saved_form->checkValue($data['fields']);
		$saved_form->submit();
		COverlayDialogElement::ensureNotPresent();
		$dashboard->save();
		$this->page->waitUntilReady();
		$this->assertMessage(TEST_GOOD, 'Dashboard updated');

		// Check new widget update interval.
		$refresh = (CTestArrayHelper::get($data['fields'], 'Refresh interval') === 'Default (1 minute)')
			? '1 minute'
			: (CTestArrayHelper::get($data['fields'], 'Refresh interval'));
		$this->assertEquals($refresh, $widget->getRefreshInterval());
	}
}
