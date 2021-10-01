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
 * Class containing methods for operations with correlations.
 */
class CCorrelation extends CApiService {

	public const ACCESS_RULES = [
		'get' => ['min_user_type' => USER_TYPE_ZABBIX_USER],
		'create' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'update' => ['min_user_type' => USER_TYPE_SUPER_ADMIN],
		'delete' => ['min_user_type' => USER_TYPE_SUPER_ADMIN]
	];

	protected $tableName = 'correlation';
	protected $tableAlias = 'c';
	protected $sortColumns = ['correlationid', 'name', 'status'];

	/**
	 * Set correlation default options in addition to global options.
	 */
	public function __construct() {
		parent::__construct();

		$this->getOptions = array_merge($this->getOptions, [
			'selectFilter'		=> null,
			'selectOperations'	=> null,
			'correlationids'	=> null,
			'editable'			=> false,
			'sortfield'			=> '',
			'sortorder'			=> ''
		]);
	}

	/**
	 * Get correlation data.
	 *
	 * @param array $options
	 *
	 * @return array|string
	 */
	public function get($options = []) {
		$options = zbx_array_merge($this->getOptions, $options);

		if ($options['editable'] && self::$userData['type'] != USER_TYPE_SUPER_ADMIN) {
			return ($options['countOutput'] && !$options['groupCount']) ? '0' : [];
		}

		$res = DBselect($this->createSelectQuery($this->tableName(), $options), $options['limit']);

		$result = [];
		while ($row = DBfetch($res)) {
			if ($options['countOutput']) {
				if ($options['groupCount']) {
					$result[] = $row;
				}
				else {
					$result = $row['rowscount'];
				}
			}
			else {
				$result[$row[$this->pk()]] = $row;
			}
		}

		if ($options['countOutput']) {
			return $result;
		}

		if ($result) {
			$result = $this->addRelatedObjects($options, $result);

			foreach ($result as &$correlation) {
				// Unset the fields that are returned in the filter.
				unset($correlation['formula'], $correlation['evaltype']);

				if ($options['selectFilter'] !== null) {
					$filter = $this->unsetExtraFields(
						[$correlation['filter']],
						['conditions', 'formula', 'evaltype'],
						$options['selectFilter']
					);
					$filter = reset($filter);

					if (array_key_exists('conditions', $filter)) {
						foreach ($filter['conditions'] as &$condition) {
							unset($condition['correlationid'], $condition['corr_conditionid']);
						}
						unset($condition);
					}

					$correlation['filter'] = $filter;
				}
			}
			unset($correlation);
		}

		// removing keys (hash -> array)
		if (!$options['preservekeys']) {
			$result = zbx_cleanHashes($result);
		}

		return $result;
	}

	/**
	 * Add correlations.
	 *
	 * @param array $correlations  An array of correlations.
	 *
	 * @return array
	 */
	public function create($correlations) {
		self::validateCreate($correlations);

		$correlation_tables = [];

		foreach ($correlations as $correlation) {
			$correlation['evaltype'] = $correlation['filter']['evaltype'];
			$correlation_tables[] = $correlation;
		}

		// Insert correlations into DB, get back array with new correlation IDs.
		$correlationids = DB::insert('correlation',$correlation_tables);

		foreach ($correlations as $index => &$correlation) {
			$correlation['correlationid'] = $correlationids[$index];
		}
		unset($correlation);

		self::updateConditionsAndOperations($correlations, __FUNCTION__);

		self::addAuditLog(CAudit::ACTION_ADD, CAudit::RESOURCE_CORRELATION, $correlations);

		return ['correlationids' => $correlationids];
	}

	private static function updateConditionsAndOperations(array &$correlations, string $method,
			array $db_correlations = null): void {
		$ins_conditions = [];
		$ins_operations = [];
		$del_corr_conditionids = [];
		$del_corr_operationids = [];

		// Collect conditions and operations to be created and set appropriate correlation ID.
		foreach ($correlations as &$correlation) {
			$db_correlation = ($method === 'update')
				? $db_correlations[$correlation['correlationid']]
				: [];

			if (array_key_exists('filter', $correlation) && array_key_exists('conditions', $correlation['filter'])) {
				$db_conditions = ($method === 'update')
					? $db_correlation['filter']['conditions']
					: [];

				foreach ($correlation['filter']['conditions'] as &$condition) {
					$db_condition = CCorrelationHelper::getDbConditionByCondition($db_conditions, $condition);
					if ($db_condition) {
						$condition['corr_conditionid'] = $db_condition['corr_conditionid'];

						unset($db_conditions[$db_condition['corr_conditionid']]);
					}
					else {
						$ins_conditions[] = [
							'correlationid' => $correlation['correlationid'],
							'type' => $condition['type']
						];
					}
				}
				unset($condition);

				$del_corr_conditionids = array_merge($del_corr_conditionids, array_keys($db_conditions));
			}

			if (!array_key_exists('operations', $correlation)) {
				continue;
			}

			$db_operations = ($method === 'update')
				? array_column($db_correlation['operations'], null, 'type')
				: [];

			foreach ($correlation['operations'] as &$operation) {
				if (array_key_exists($operation['type'], $db_operations)) {
					$operation['corr_operationid'] = $db_operations[$operation['type']]['corr_operationid'];
					unset($db_operations[$operation['type']]);
				}
				else {
					$ins_operations[] = ['correlationid' => $correlation['correlationid']] + $operation;
				}
			}
			unset($operation);

			$del_corr_operationids = array_merge($del_corr_operationids,
				array_column($db_operations, 'corr_operationid')
			);
		}
		unset($correlation);

		if ($ins_conditions) {
			$conditionids = DB::insert('corr_condition', $ins_conditions);
		}

		if ($ins_operations) {
			$operationids = DB::insert('corr_operation', $ins_operations);
		}

		if ($del_corr_operationids) {
			DB::delete('corr_operation', ['corr_operationid' => $del_corr_operationids]);
		}

		if ($del_corr_conditionids) {
			DB::delete('corr_condition', ['corr_conditionid' => $del_corr_conditionids]);
			DB::delete('corr_condition_tag', ['corr_conditionid' => $del_corr_conditionids]);
			DB::delete('corr_condition_group', ['corr_conditionid' => $del_corr_conditionids]);
			DB::delete('corr_condition_tagpair', ['corr_conditionid' => $del_corr_conditionids]);
			DB::delete('corr_condition_tagvalue', ['corr_conditionid' => $del_corr_conditionids]);
		}

		$ins_condition_tags = [];
		$ins_condition_hostgroups = [];
		$ins_condition_tag_pairs = [];
		$ins_condition_tag_values = [];

		$upd_condition_tags = [];
		$upd_condition_hostgroups = [];
		$upd_condition_tag_pairs = [];
		$upd_condition_tag_values = [];

		foreach ($correlations as &$correlation) {
			if (array_key_exists('filter', $correlation) && array_key_exists('conditions', $correlation['filter'])) {
				foreach ($correlation['filter']['conditions'] as &$condition) {
					if (array_key_exists('corr_conditionid', $condition)) {
						switch ($condition['type']) {
							case ZBX_CORR_CONDITION_OLD_EVENT_TAG:
							case ZBX_CORR_CONDITION_NEW_EVENT_TAG:
								$upd_condition_tags[] = [
									'values' => $condition,
									'where' => ['corr_conditionid' => $condition['corr_conditionid']]
								];
								break;

							case ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP:
								$upd_condition_hostgroups[] = [
									'values' => $condition,
									'where' => ['corr_conditionid' => $condition['corr_conditionid']]
								];
								break;

							case ZBX_CORR_CONDITION_EVENT_TAG_PAIR:
								$upd_condition_tag_pairs[] = [
									'values' => $condition,
									'where' => ['corr_conditionid' => $condition['corr_conditionid']]
								];
								break;

							case ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE:
							case ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE:
								$upd_condition_tag_values[] = [
									'values' => $condition,
									'where' => ['corr_conditionid' => $condition['corr_conditionid']]
								];
								break;
						}
					}
					else {
						$condition['corr_conditionid'] = array_shift($conditionids);

						switch ($condition['type']) {
							case ZBX_CORR_CONDITION_OLD_EVENT_TAG:
							case ZBX_CORR_CONDITION_NEW_EVENT_TAG:
								$ins_condition_tags[] = $condition;
								break;

							case ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP:
								$ins_condition_hostgroups[] = $condition;
								break;

							case ZBX_CORR_CONDITION_EVENT_TAG_PAIR:
								$ins_condition_tag_pairs[] = $condition;
								break;

							case ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE:
							case ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE:
								$ins_condition_tag_values[] = $condition;
								break;
						}
					}
				}
				unset($condition);

				if (array_key_exists('evaltype', $correlation['filter'])
						&& $correlation['filter']['evaltype']
							!= $db_correlations[$correlation['correlationid']]['filter']['evaltype']) {
					if ($correlation['filter']['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
						self::updateFormula($correlation['correlationid'], $correlation['filter']['formula'],
							$correlation['filter']['conditions']
						);
					}
				}
			}

			if (array_key_exists('operations', $correlation)) {
				foreach ($correlation['operations'] as &$operation) {
					if (!array_key_exists('corr_operationid', $operation)) {
						$operation['corr_operationid'] = array_shift($operationids);
					}
				}
				unset($operation);
			}
		}
		unset($correlation);

		if ($ins_condition_tags) {
			DB::insert('corr_condition_tag', $ins_condition_tags, false);
		}

		if ($ins_condition_hostgroups) {
			DB::insert('corr_condition_group', $ins_condition_hostgroups, false);
		}

		if ($ins_condition_tag_pairs) {
			DB::insert('corr_condition_tagpair', $ins_condition_tag_pairs, false);
		}

		if ($ins_condition_tag_values) {
			DB::insert('corr_condition_tagvalue', $ins_condition_tag_values, false);
		}

		if ($upd_condition_tags) {
			DB::update('corr_condition_tag', $upd_condition_tags);
		}

		if ($upd_condition_hostgroups) {
			DB::update('corr_condition_group', $upd_condition_hostgroups);
		}

		if ($upd_condition_tag_pairs) {
			DB::update('corr_condition_tagpair', $upd_condition_tag_pairs);
		}

		if ($upd_condition_tag_values) {
			DB::update('corr_condition_tagvalue', $upd_condition_tag_values);
		}
	}

	/**
	 * Update correlations.
	 *
	 * @param array $correlations  An array of correlations.
	 *
	 * @return array
	 */
	public function update($correlations) {
		self::validateUpdate($correlations, $db_correlations);

		$upd_correlations = [];

		foreach ($correlations as $correlation) {
			$correlationid = $correlation['correlationid'];

			$correlation_update_object = [];
			$db_correlation_update_object = [];
			if (array_key_exists('name', $correlation)) {
				$correlation_update_object['name'] = $correlation['name'];
				$db_correlation_update_object['name'] = $db_correlations[$correlationid]['name'];
			}
			if (array_key_exists('description', $correlation)) {
				$correlation_update_object['description'] = $correlation['description'];
				$db_correlation_update_object['description'] = $db_correlations[$correlationid]['description'];
			}
			if (array_key_exists('status', $correlation)) {
				$correlation_update_object['status'] = $correlation['status'];
				$db_correlation_update_object['status'] = $db_correlations[$correlationid]['status'];
			}
			if (array_key_exists('filter', $correlation) && array_key_exists('evaltype', $correlation['filter'])) {
				$correlation_update_object['evaltype'] = $correlation['filter']['evaltype'];
				$db_correlation_update_object['evaltype'] = $db_correlations[$correlationid]['filter']['evaltype'];
			}
			if (array_key_exists('filter', $correlation) && array_key_exists('formula', $correlation['filter'])) {
				$correlation_update_object['formula'] = $correlation['filter']['formula'];
				$db_correlation_update_object['formula'] = $db_correlations[$correlationid]['filter']['formula'];
			}

			$upd_correlation = DB::getUpdatedValues('correlation', $correlation_update_object,
				$db_correlation_update_object
			);

			if ($upd_correlation) {
				$upd_correlations[] = [
					'values' => $upd_correlation,
					'where' => ['correlationid' => $correlationid]
				];
			}
		}

		if ($upd_correlations) {
			DB::update('correlation', $upd_correlations);
		}

		self::updateConditionsAndOperations($correlations, __FUNCTION__, $db_correlations);

		self::addAuditLog(CAudit::ACTION_UPDATE, CAudit::RESOURCE_CORRELATION, $correlations, $db_correlations);

		return ['correlationids' => array_column($correlations, 'correlationid')];
	}

	/**
	 * Delete correlations.
	 *
	 * @param array $correlationids  An array of correlation IDs.
	 *
	 * @return array
	 */
	public function delete(array $correlationids) {
		self::validateDelete($correlationids, $db_correlations);

		DB::delete('correlation', ['correlationid' => $correlationids]);

		self::addAuditLog(CAudit::ACTION_DELETE, CAudit::RESOURCE_CORRELATION, $db_correlations);

		return ['correlationids' => $correlationids];
	}

	/**
	 * Validates the input parameters for the delete() method.
	 *
	 * @param array      $correlationids
	 * @param array|null $db_correlations
	 *
	 * @throws APIException if the input is invalid.
	 */
	private static function validateDelete(array $correlationids, array &$db_correlations = null): void {
		$api_input_rules = ['type' => API_IDS, 'flags' => API_NOT_EMPTY, 'uniq' => true];

		if (!CApiInputValidator::validate($api_input_rules, $correlationids, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$db_correlations = DB::select('correlation', [
			'output' => ['correlationid', 'name'],
			'correlationids' => $correlationids,
			'preservekeys' => true
		]);

		if (count($db_correlations) != count($correlationids)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}
	}

	/**
	 * Check for unique event correlation names.
	 *
	 * @static
	 *
	 * @param array      $correlations
	 * @param array|null $db_correlations
	 *
	 * @throws APIException if event correlation  names are not unique.
	 */
	protected static function checkDuplicates(array $correlations, array $db_correlations = null): void {
		$names = [];

		foreach ($correlations as $correlation) {
			if (!array_key_exists('name', $correlation)) {
				continue;
			}

			if ($db_correlations === null
					|| $correlation['name'] !== $db_correlations[$correlation['correlationid']]['name']) {
				$names[] = $correlation['name'];
			}
		}

		if (!$names) {
			return;
		}

		$duplicates = DB::select('correlation', [
			'output' => ['name'],
			'filter' => ['name' => $names],
			'limit' => 1
		]);

		if ($duplicates) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _s('Correlation "%1$s" already exists.', $duplicates[0]['name']));
		}
	}

	/**
	 * Validates the input parameters for the create() method.
	 *
	 * @param array $correlations
	 *
	 * @throws APIException if the input is invalid.
	 */
	private static function validateCreate(array &$correlations): void {
		$api_input_rules = ['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY | API_NORMALIZE, 'uniq' => [['name']], 'fields' => [
			'name' =>			['type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('correlation', 'name')],
			'description' =>	['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('correlation', 'description')],
			'status' =>			['type' => API_INT32, 'in' => ZBX_CORRELATION_ENABLED.','.ZBX_CORRELATION_DISABLED],
			'filter' =>			['type' => API_OBJECT, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'fields' => [
				'evaltype' =>		['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [CONDITION_EVAL_TYPE_AND_OR, CONDITION_EVAL_TYPE_AND, CONDITION_EVAL_TYPE_OR, CONDITION_EVAL_TYPE_EXPRESSION])],
				'formula' =>		['type' => API_MULTIPLE, 'rules' => [
										['if' => ['field' => 'evaltype', 'in' => CONDITION_EVAL_TYPE_EXPRESSION], 'type' => API_COND_FORMULA, 'flags' => API_REQUIRED, 'length' => DB::getFieldLength('correlation', 'formula')],
										['if' => ['field' => 'evaltype', 'in' => implode(',', [CONDITION_EVAL_TYPE_AND_OR, CONDITION_EVAL_TYPE_AND, CONDITION_EVAL_TYPE_OR])], 'type' => API_STRING_UTF8, 'in' => '']
				]],
				'conditions' =>		['type' => API_OBJECTS, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'uniq' => [['formulaid']], 'fields' => [
					'type' =>			['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_EVENT_TAG_PAIR, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])],
					'formulaid' =>		['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('correlation', 'formula')],
					'operator' =>		['type' => API_MULTIPLE, 'flags' => API_REQUIRED, 'rules' => [
											['if' => ['field' => 'type', 'in' => ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP], 'type' => API_INT32, 'in' => implode(',', [CONDITION_OPERATOR_EQUAL, CONDITION_OPERATOR_NOT_EQUAL])],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_INT32, 'in' => implode(',', [CONDITION_OPERATOR_EQUAL, CONDITION_OPERATOR_NOT_EQUAL, CONDITION_OPERATOR_LIKE, CONDITION_OPERATOR_NOT_LIKE])],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_EVENT_TAG_PAIR])], 'type' => API_INT32, 'in' => CONDITION_OPERATOR_EQUAL]
					]],
					'tag' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('corr_condition_tag', 'tag')],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_EVENT_TAG_PAIR])], 'type' => API_STRING_UTF8, 'in' => '']
					]],
					'groupid' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP], 'type' => API_ID, 'flags' => API_REQUIRED | API_NOT_EMPTY],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_EVENT_TAG_PAIR, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'in' => '']
					]],
					'oldtag' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => ZBX_CORR_CONDITION_EVENT_TAG_PAIR], 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('corr_condition_tagpair', 'oldtag')],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'in' => '']
					]],
					'newtag' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => ZBX_CORR_CONDITION_EVENT_TAG_PAIR], 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('corr_condition_tagpair', 'newtag')],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'in' => '']
					]],
					'value' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('corr_condition_tagvalue', 'value')],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_EVENT_TAG_PAIR])], 'type' => API_STRING_UTF8, 'in' => '']
					]]
				]]
			]],
			'operations' =>		['type' => API_OBJECTS, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'uniq' => [['type']], 'fields' => [
				'type' =>			['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => ZBX_CORR_OPERATION_CLOSE_OLD.','.ZBX_CORR_OPERATION_CLOSE_NEW],
			]]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $correlations, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		self::checkDuplicates($correlations);
		self::validateFormula($correlations, __FUNCTION__);
		self::checkHostGroups($correlations);
	}

	/**
	 * Check host group permissions.
	 *
	 * @static
	 *
	 * @param array $correlations
	 */
	private static function checkHostGroups(array $correlations): void {
		$groupids = [];

		foreach ($correlations as $correlation) {
			if (!array_key_exists('filter', $correlation) || !array_key_exists('conditions', $correlation['filter'])) {
				continue;
			}

			foreach ($correlation['filter']['conditions'] as $condition) {
				if ($condition['type'] == ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP) {
					$groupids[$condition['groupid']] = true;
				}
			}
		}

		if (!$groupids) {
			return;
		}

		$groups_count = API::HostGroup()->get([
			'countOutput' => true,
			'groupids' => array_keys($groupids)
		]);

		if ($groups_count != count($groupids)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}
	}

	/**
	 * @param array      $correlations
	 * @param array|null $db_correlations
	 *
	 * @throws APIException if the input is invalid.
	 */
	private static function validateUpdate(array &$correlations, array &$db_correlations = null): void {
		$api_input_rules = ['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY | API_NORMALIZE, 'uniq' => [['correlationid'], ['name']], 'fields' => [
			'correlationid' =>	['type' => API_ID, 'flags' => API_REQUIRED],
			'name' =>			['type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('correlation', 'name')],
			'description' =>	['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('correlation', 'description')],
			'status' =>			['type' => API_INT32, 'in' => ZBX_CORRELATION_ENABLED.','.ZBX_CORRELATION_DISABLED],
			'filter' =>			['type' => API_OBJECT, 'flags' => API_NOT_EMPTY, 'fields' => [
				'evaltype' =>		['type' => API_INT32, 'in' => implode(',', [CONDITION_EVAL_TYPE_AND_OR, CONDITION_EVAL_TYPE_AND, CONDITION_EVAL_TYPE_OR, CONDITION_EVAL_TYPE_EXPRESSION])],
				'formula' =>		['type' => API_MULTIPLE, 'rules' => [
										['if' => ['field' => 'evaltype', 'in' => CONDITION_EVAL_TYPE_EXPRESSION], 'type' => API_COND_FORMULA, 'flags' => API_REQUIRED, 'length' => DB::getFieldLength('correlation', 'formula')],
										['if' => ['field' => 'evaltype', 'in' => implode(',', [CONDITION_EVAL_TYPE_AND_OR, CONDITION_EVAL_TYPE_AND, CONDITION_EVAL_TYPE_OR])], 'type' => API_STRING_UTF8, 'in' => '']
				]],
				'conditions' =>		['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY, 'uniq' => [['formulaid']], 'fields' => [
					'type' =>			['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_EVENT_TAG_PAIR, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])],
					'formulaid' =>		['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('correlation', 'formula')],
					'operator' =>		['type' => API_MULTIPLE, 'flags' => API_REQUIRED, 'rules' => [
											['if' => ['field' => 'type', 'in' => ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP], 'type' => API_INT32, 'in' => implode(',', [CONDITION_OPERATOR_EQUAL, CONDITION_OPERATOR_NOT_EQUAL])],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_INT32, 'in' => implode(',', [CONDITION_OPERATOR_EQUAL, CONDITION_OPERATOR_NOT_EQUAL, CONDITION_OPERATOR_LIKE, CONDITION_OPERATOR_NOT_LIKE])],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_EVENT_TAG_PAIR])], 'type' => API_INT32, 'in' => CONDITION_OPERATOR_EQUAL]
					]],
					'tag' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('corr_condition_tag', 'tag')],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_EVENT_TAG_PAIR])], 'type' => API_STRING_UTF8, 'in' => '']
					]],
					'groupid' =>		['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP], 'type' => API_ID, 'flags' => API_REQUIRED | API_NOT_EMPTY],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_EVENT_TAG_PAIR, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'in' => '']
					]],
					'oldtag' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => ZBX_CORR_CONDITION_EVENT_TAG_PAIR], 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('corr_condition_tagpair', 'oldtag')],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'in' => '']
					]],
					'newtag' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => ZBX_CORR_CONDITION_EVENT_TAG_PAIR], 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('corr_condition_tagpair', 'newtag')],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'in' => '']
					]],
					'value' =>			['type' => API_MULTIPLE, 'rules' => [
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE, ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE])], 'type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('corr_condition_tagvalue', 'value')],
											['if' => ['field' => 'type', 'in' => implode(',', [ZBX_CORR_CONDITION_OLD_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_TAG, ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP, ZBX_CORR_CONDITION_EVENT_TAG_PAIR])], 'type' => API_STRING_UTF8, 'in' => '']
					]]
				]]
			]],
			'operations' =>		['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY, 'uniq' => [['type']], 'fields' => [
				'type' =>			['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => ZBX_CORR_OPERATION_CLOSE_OLD.','.ZBX_CORR_OPERATION_CLOSE_NEW],
			]]
		]];

		if (!CApiInputValidator::validate($api_input_rules, $correlations, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$db_correlations = DB::select('correlation', [
			'output' => ['correlationid', 'name', 'description', 'status'],
			'correlationids' => array_column($correlations, 'correlationid'),
			'preservekeys' => true
		]);

		self::checkDuplicates($correlations, $db_correlations);

		self::addAffectedObjects($correlations, $db_correlations);
		self::validateFormula($correlations, __FUNCTION__, $db_correlations);
		self::checkHostGroups($correlations);
	}

	/**
	 * Converts a formula with letters to a formula with IDs and updates it.
	 *
	 * @param string 	$correlationid
	 * @param string 	$formula_with_letters		Formula with letters.
	 * @param array 	$conditions
	 */
	protected static function updateFormula($correlationid, $formula_with_letters, array $conditions) {
		$formulaid_to_conditionid = [];

		foreach ($conditions as $condition) {
			$formulaid_to_conditionid[$condition['formulaid']] = $condition['corr_conditionid'];
		}
		$formula = CConditionHelper::replaceLetterIds($formula_with_letters, $formulaid_to_conditionid);

		DB::updateByPk('correlation', $correlationid, ['formula' => $formula]);
	}

	/**
	 * Validate correlation conditions. Check the "conditions" array, check the "type" field and other fields that
	 * depend on it. As a result return host group IDs that need to be validated afterwards. Otherwise don't return
	 * anything, just throw an error.
	 *
	 * @param array					$correlation											One correlation containing the conditions.
	 * @param string				$correlation['name']									Correlation name for error messages.
	 * @param array					$correlation['filter']									Correlation filter array containing	the conditions.
	 * @param array					$correlation['filter']['conditions']					An array of correlation conditions.
	 * @param int					$correlation['filter']['conditions'][]['type']			Condition type.
	 *																						Possible values are:
	 *																							0 - ZBX_CORR_CONDITION_OLD_EVENT_TAG;
	 *																							1 - ZBX_CORR_CONDITION_NEW_EVENT_TAG;
	 *																							2 - ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP;
	 *																							3 - ZBX_CORR_CONDITION_EVENT_TAG_PAIR;
	 *																							4 - ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE;
	 *																							5 - ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE.
	 * @param int					$correlation['filter']['conditions'][]['operator']		Correlation condition operator.
	 *																						Possible values when "type"
	 *																						is one of the following:
	 *																							2 - ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP;
	 *																							4 - ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE;
	 *																							5 - ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE.
	 *																						Possible values depend on type:
	 *																							for type ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP:
	 *																								0 - CONDITION_OPERATOR_EQUAL
	 *																								1 - CONDITION_OPERATOR_NOT_EQUAL
	 *																							for types ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE
	 *																							or ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE:
	 *																								0 - CONDITION_OPERATOR_EQUAL;
	 *																								1 - CONDITION_OPERATOR_NOT_EQUAL;
	 *																								2 - CONDITION_OPERATOR_LIKE;
	 *																								3 - CONDITION_OPERATOR_NOT_LIKE.
	 * @param string				$correlations['filter']['conditions'][]['groupid']		Correlation host group ID.
	 *																						Required when "type" is:
	 *																							2 - ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP.
	 * @param CLimitedSetValidator	$filter_condition_type_validator						Validator for conditype type.
	 * @param CLimitedSetValidator	$filter_condition_hg_operator_validator					Validator for host group operator.
	 * @param CLimitedSetValidator	$filter_condition_tagval_operator_validator				Validator for tag value operator.
	 *
	 * @throws APIException if the input is invalid.
	 *
	 * @return array
	 */
	protected function validateConditions(array $correlation, CLimitedSetValidator $filter_condition_type_validator,
			CLimitedSetValidator $filter_condition_hg_operator_validator,
			CLimitedSetValidator $filter_condition_tagval_operator_validator) {
		if (!$correlation['filter']['conditions']) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('No "%1$s" given for correlation "%2$s".', 'conditions', $correlation['name'])
			);
		}

		if (!is_array($correlation['filter']['conditions'])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
		}

		$groupids = [];
		$formulaIds = [];
		$conditions = [];

		foreach ($correlation['filter']['conditions'] as $condition) {
			if (!array_key_exists('type', $condition)) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('No condition type given for correlation "%1$s".', $correlation['name'])
				);
			}
			elseif (is_array($condition['type'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
			}
			elseif (!$filter_condition_type_validator->validate($condition['type'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Incorrect value "%1$s" in field "%2$s" for correlation "%3$s".',
					$condition['type'],
					'type',
					$correlation['name']
				));
			}

			switch ($condition['type']) {
				case ZBX_CORR_CONDITION_OLD_EVENT_TAG:
				case ZBX_CORR_CONDITION_NEW_EVENT_TAG:
					if (!array_key_exists('tag', $condition)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('No "%1$s" given for correlation "%2$s".', 'tag', $correlation['name'])
						);
					}
					elseif (is_array($condition['tag'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_('Incorrect arguments passed to function.')
						);
					}
					elseif ($condition['tag'] === '' || $condition['tag'] === null || $condition['tag'] === false) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect value for field "%1$s": %2$s.', 'tag', _('cannot be empty'))
						);
					}
					break;

				case ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP:
					if (array_key_exists('operator', $condition)) {
						if (is_array($condition['operator'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS,
								_('Incorrect arguments passed to function.')
							);
						}
						elseif (!$filter_condition_hg_operator_validator->validate($condition['operator'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _s(
								'Incorrect value "%1$s" in field "%2$s" for correlation "%3$s".',
								$condition['operator'],
								'operator',
								$correlation['name']
							));
						}
					}

					if (!array_key_exists('groupid', $condition)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('No "%1$s" given for correlation "%2$s".', 'groupid', $correlation['name'])
						);
					}
					elseif (is_array($condition['groupid'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_('Incorrect arguments passed to function.')
						);
					}
					elseif ($condition['groupid'] === '' || $condition['groupid'] === null
							|| $condition['groupid'] === false) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect value for field "%1$s": %2$s.', 'groupid', _('cannot be empty'))
						);
					}

					$groupids[$condition['groupid']] = true;
					break;

				case ZBX_CORR_CONDITION_EVENT_TAG_PAIR:
					if (!array_key_exists('oldtag', $condition)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('No "%1$s" given for correlation "%2$s".', 'oldtag', $correlation['name'])
						);
					}
					elseif (is_array($condition['oldtag'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_('Incorrect arguments passed to function.')
						);
					}
					elseif ($condition['oldtag'] === '' || $condition['oldtag'] === null
							|| $condition['oldtag'] === false) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect value for field "%1$s": %2$s.', 'oldtag', _('cannot be empty'))
						);
					}

					if (!array_key_exists('newtag', $condition)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('No "%1$s" given for correlation "%2$s".', 'newtag', $correlation['name'])
						);
					}
					elseif (is_array($condition['newtag'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_('Incorrect arguments passed to function.')
						);
					}
					elseif ($condition['newtag'] === '' || $condition['newtag'] === null
							|| $condition['newtag'] === false) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect value for field "%1$s": %2$s.', 'newtag', _('cannot be empty'))
						);
					}
					break;

				case ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE:
				case ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE:
					if (!array_key_exists('tag', $condition)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('No "%1$s" given for correlation "%2$s".', 'tag', $correlation['name'])
						);
					}
					elseif (is_array($condition['tag'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_('Incorrect arguments passed to function.')
						);
					}
					elseif ($condition['tag'] === '' || $condition['tag'] === null || $condition['tag'] === false) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Incorrect value for field "%1$s": %2$s.', 'tag', _('cannot be empty'))
						);
					}

					if (array_key_exists('operator', $condition)) {
						if (is_array($condition['operator'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS,
								_('Incorrect arguments passed to function.')
							);
						}
						elseif (($condition['operator'] == CONDITION_OPERATOR_LIKE
									|| $condition['operator'] == CONDITION_OPERATOR_NOT_LIKE)
								&& (!array_key_exists('value', $condition) || $condition['value'] === '')) {
							self::exception(ZBX_API_ERROR_PARAMETERS,
								_s('Incorrect value for field "%1$s": %2$s.', 'value', _('cannot be empty'))
							);
						}
						elseif (!$filter_condition_tagval_operator_validator->validate($condition['operator'])) {
							self::exception(ZBX_API_ERROR_PARAMETERS, _s(
								'Incorrect value "%1$s" in field "%2$s" for correlation "%3$s".',
								$condition['operator'],
								'operator',
								$correlation['name']
							));
						}
					}
					break;
			}

			if ($correlation['filter']['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
				if (array_key_exists($condition['formulaid'], $formulaIds)) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _s(
						'Duplicate "%1$s" value "%2$s" for correlation "%3$s".', 'formulaid', $condition['formulaid'],
							$correlation['name']
					));
				}
				else {
					$formulaIds[$condition['formulaid']] = true;
				}
			}

			unset($condition['formulaid']);
			$conditions[] = $condition;
		}

		if (count($conditions) != count(array_unique($conditions, SORT_REGULAR))) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Duplicate conditions for correlation "%1$s".', $correlation['name'])
			);
		}

		return $groupids;
	}

	/**
	 * Validate correlation filter "formula" field.
	 *
	 * @param array				$correlation						One correlation containing the filter, formula and name.
	 * @param string			$correlation['name']				Correlation name for error messages.
	 * @param array				$correlation['filter']				Correlation filter array containing the formula.
	 * @param string			$correlation['filter']['formula']	User-defined expression to be used for evaluating
	 *																conditions of filters with a custom expression.
	 * @param CConditionFormula $parser								Condition formula parser.
	 *
	 * @throws APIException if the input is invalid.
	 */
	// protected function validateFormula(array $correlation, CConditionFormula $parser) {
	// 	if (is_array($correlation['filter']['formula'])) {
	// 		self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
	// 	}

	// 	if (!$parser->parse($correlation['filter']['formula'])) {
	// 		self::exception(ZBX_API_ERROR_PARAMETERS,
	// 			_s('Incorrect custom expression "%2$s" for correlation "%1$s": %3$s.',
	// 				$correlation['name'], $correlation['filter']['formula'], $parser->error
	// 			)
	// 		);
	// 	}
	// }

	/**
	 * Validate correlation condition formula IDs. Check the "formulaid" field and that formula matches the conditions.
	 *
	 * @static
	 *
	 * @param array $correlations
	 *
	 * @throws APIException if the input is invalid.
	 */
	protected static function validateFormula(array $correlations, string $method, array $db_correlations = null): void {
		$parser = new CConditionFormula();

		foreach ($correlations as $i => $correlation) {
			if (!array_key_exists('filter', $correlation)) {
				continue;
			}

			$filter_evaltype = ($method === 'validateUpdate')
				? (
					array_key_exists('evaltype', $correlation['filter'])
						? $correlation['filter']['evaltype']
						: $db_correlations['filter']['evaltype']
				)
				: $correlation['filter']['evaltype'];

			if ($filter_evaltype != CONDITION_EVAL_TYPE_EXPRESSION) {
				if (!array_key_exists('conditions', $correlation['filter'])) {
					continue;
				}

				foreach ($correlation['filter']['conditions'] as $j => $condition) {
					if (array_key_exists('formulaid', $condition) && $condition['formulaid'] != '') {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Invalid parameter "%1$s": %2$s.',
								'/'.($i + 1).'/filter/conditions/'.($j + 1).'/formulaid',
								_('value must be empty')
							)
						);
					}
				}
			}
			else {
				if (!array_key_exists('conditions', $correlation['filter'])) {
					continue;
				}

				$parser->parse($correlation['filter']['formula']);

				$conditions = array_column($correlation['filter']['conditions'], 'formulaid', 'formulaid');
				$constants = array_column($parser->constants, 'value', 'value');

				if (count($conditions) != count($constants)) {
					self::exception(ZBX_API_ERROR_PARAMETERS,
						_s('Invalid parameter "%1$s": %2$s.', '/'.($i + 1).'/filter/formula', _('incorrect value')) // FIXME: rephrase message
					);
				}

				foreach ($correlation['filter']['conditions'] as $j => $condition) {
					if (!preg_match('/^[A-Z]+$/', $condition['formulaid'])) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Invalid parameter "%1$s": %2$s.',
								'/'.($i + 1).'/filter/conditions/'.($j + 1).'/formulaid',
								_('incorrect value')
							)
						);
					}

					if (!array_key_exists($condition['formulaid'], $constants)) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Invalid parameter "%1$s": %2$s.',
								'/'.($i + 1).'/filter/conditions/'.($j + 1).'/formulaid',
								_s('not defined in %1$s', '/'.($i + 1).'/filter/formula')
							)
						);
					}
				}
			}
		}
	}

	/**
	 * Validate correlation operations. Check if "operations" is valid, if "type" is valid and there are no duplicate
	 * operations in correlation.
	 *
	 * @param array					$correlation						One correlation containing array of operations and name.
	 * @param string				$correlation['name']				Correlation name for error messages.
	 * @param array					$correlation['operations']			An array of correlation operations.
	 * @param int					$correlation['operations']['type']	Correlation operation type.
	 *																	Possible values are:
	 *																		0 - ZBX_CORR_OPERATION_CLOSE_OLD;
	 *																		1 - ZBX_CORR_OPERATION_CLOSE_NEW.
	 * @param CLimitedSetValidator	$filter_operations_validator		Operations validator.
	 *
	 * @throws APIException if the input is invalid.
	 */
	protected function validateOperations(array $correlation, CLimitedSetValidator $filter_operations_validator) {
		if (!is_array($correlation['operations'])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
		}
		elseif (!$correlation['operations']) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('No "%1$s" given for correlation "%2$s".', 'operations', $correlation['name'])
			);
		}

		foreach ($correlation['operations'] as $operation) {
			if (!array_key_exists('type', $operation)) {
				self::exception(ZBX_API_ERROR_PARAMETERS,
					_s('No operation type given for correlation "%1$s".', $correlation['name'])
				);
			}
			elseif (is_array($operation['type'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
			}

			if (!$filter_operations_validator->validate($operation['type'])) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s(
					'Incorrect value "%1$s" in field "%2$s" for correlation "%3$s".',
					$operation['type'],
					'type',
					$correlation['name']
				));
			}
		}

		// Check that same operation types do not repeat.
		$duplicate = CArrayHelper::findDuplicate($correlation['operations'], 'type');
		if ($duplicate) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Duplicate "%1$s" value "%2$s" for correlation "%3$s".', 'type', $duplicate['type'],
					$correlation['name']
				)
			);
		}
	}

	/**
	 * Insert correlation condition values to their corresponding DB tables.
	 *
	 * @param array $conditions		An array of conditions to create.
	 *
	 * @return array
	 */
	protected function addConditions(array $conditions) {
		$conditionids = DB::insert('corr_condition', $conditions);

		$corr_condition_tags_to_create = [];
		$corr_condition_hostgroups_to_create = [];
		$corr_condition_tag_pairs_to_create = [];
		$corr_condition_tag_values_to_create = [];

		foreach ($conditions as $index => &$condition) {
			$condition = ['corr_conditionid' => $conditionids[$index]] + $condition;

			switch ($condition['type']) {
				case ZBX_CORR_CONDITION_OLD_EVENT_TAG:
				case ZBX_CORR_CONDITION_NEW_EVENT_TAG:
					$corr_condition_tags_to_create[] = $condition;
					break;

				case ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP:
					$corr_condition_hostgroups_to_create[] = $condition;
					break;

				case ZBX_CORR_CONDITION_EVENT_TAG_PAIR:
					$corr_condition_tag_pairs_to_create[] = $condition;
					break;

				case ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE:
				case ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE:
					$corr_condition_tag_values_to_create[] = $condition;
					break;
			}
		}
		unset($condition);

		if ($corr_condition_tags_to_create) {
			DB::insert('corr_condition_tag', $corr_condition_tags_to_create, false);
		}

		if ($corr_condition_hostgroups_to_create) {
			DB::insert('corr_condition_group', $corr_condition_hostgroups_to_create, false);
		}

		if ($corr_condition_tag_pairs_to_create) {
			DB::insert('corr_condition_tagpair', $corr_condition_tag_pairs_to_create, false);
		}

		if ($corr_condition_tag_values_to_create) {
			DB::insert('corr_condition_tagvalue', $corr_condition_tag_values_to_create, false);
		}

		return $conditions;
	}

	/**
	 * Apply query output options.
	 *
	 * @param type $table_name
	 * @param type $table_alias
	 * @param array $options
	 * @param array $sql_parts
	 *
	 * @return array
	 */
	protected function applyQueryOutputOptions($table_name, $table_alias, array $options, array $sql_parts) {
		$sql_parts = parent::applyQueryOutputOptions($table_name, $table_alias, $options, $sql_parts);

		if (!$options['countOutput']) {
			// Add filter fields.
			if ($this->outputIsRequested('formula', $options['selectFilter'])
					|| $this->outputIsRequested('eval_formula', $options['selectFilter'])
					|| $this->outputIsRequested('conditions', $options['selectFilter'])) {

				$sql_parts = $this->addQuerySelect('c.formula', $sql_parts);
				$sql_parts = $this->addQuerySelect('c.evaltype', $sql_parts);
			}

			if ($this->outputIsRequested('evaltype', $options['selectFilter'])) {
				$sql_parts = $this->addQuerySelect('c.evaltype', $sql_parts);
			}
		}

		return $sql_parts;
	}

	/**
	 * Extend result with requested objects.
	 *
	 * @param array $options
	 * @param array $result
	 *
	 * @return array
	 */
	protected function addRelatedObjects(array $options, array $result) {
		$result = parent::addRelatedObjects($options, $result);

		$correlationids = array_keys($result);

		// Adding formulas and conditions.
		if ($options['selectFilter'] !== null) {
			$formula_requested = $this->outputIsRequested('formula', $options['selectFilter']);
			$eval_formula_requested = $this->outputIsRequested('eval_formula', $options['selectFilter']);
			$conditions_requested = $this->outputIsRequested('conditions', $options['selectFilter']);

			$filters = [];

			if ($options['selectFilter']) {
				foreach ($result as $correlation) {
					$filters[$correlation['correlationid']] = [
						'evaltype' => $correlation['evaltype'],
						'formula' => array_key_exists('formula', $correlation) ? $correlation['formula'] : '',
						'conditions' => []
					];
				}

				if ($formula_requested || $eval_formula_requested || $conditions_requested) {
					$sql = 'SELECT c.correlationid,c.corr_conditionid,c.type,ct.tag AS ct_tag,'.
								'cg.operator AS cg_operator,cg.groupid,ctp.oldtag,ctp.newtag,ctv.tag AS ctv_tag,'.
								'ctv.operator AS ctv_operator,ctv.value'.
							' FROM corr_condition c'.
							' LEFT JOIN corr_condition_tag ct ON ct.corr_conditionid = c.corr_conditionid'.
							' LEFT JOIN corr_condition_group cg ON cg.corr_conditionid = c.corr_conditionid'.
							' LEFT JOIN corr_condition_tagpair ctp ON ctp.corr_conditionid = c.corr_conditionid'.
							' LEFT JOIN corr_condition_tagvalue ctv ON ctv.corr_conditionid = c.corr_conditionid'.
							' WHERE '.dbConditionInt('c.correlationid', $correlationids);

					$db_corr_conditions = DBselect($sql);

					while ($row = DBfetch($db_corr_conditions)) {
						$fields = [
							'corr_conditionid' => $row['corr_conditionid'],
							'correlationid' => $row['correlationid'],
							'type' => $row['type']
						];

						switch ($row['type']) {
							case ZBX_CORR_CONDITION_OLD_EVENT_TAG:
							case ZBX_CORR_CONDITION_NEW_EVENT_TAG:
								$fields['tag'] = $row['ct_tag'];
								break;

							case ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP:
								$fields['operator'] = $row['cg_operator'];
								$fields['groupid'] = $row['groupid'];
								break;

							case ZBX_CORR_CONDITION_EVENT_TAG_PAIR:
								$fields['oldtag'] = $row['oldtag'];
								$fields['newtag'] = $row['newtag'];
								break;

							case ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE:
							case ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE:
								$fields['tag'] = $row['ctv_tag'];
								$fields['operator'] = $row['ctv_operator'];
								$fields['value'] = $row['value'];
								break;
						}

						$filters[$row['correlationid']]['conditions'][] = $fields;
					}

					foreach ($filters as &$filter) {
						// In case of a custom expression, use the given formula.
						if ($filter['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
							$formula = $filter['formula'];
						}
						// In other cases generate the formula automatically.
						else {
							$conditions = $filter['conditions'];
							CArrayHelper::sort($conditions, ['type']);
							$conditions_for_formula = [];

							foreach ($conditions as $condition) {
								$conditions_for_formula[$condition['corr_conditionid']] = $condition['type'];
							}

							$formula = CConditionHelper::getFormula($conditions_for_formula, $filter['evaltype']);
						}

						// Generate formulaids from the effective formula.
						$formulaids = CConditionHelper::getFormulaIds($formula);

						foreach ($filter['conditions'] as &$condition) {
							$condition['formulaid'] = $formulaids[$condition['corr_conditionid']];
						}
						unset($condition);

						// Generated a letter based formula only for actions with custom expressions.
						if ($formula_requested && $filter['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
							$filter['formula'] = CConditionHelper::replaceNumericIds($formula, $formulaids);
						}

						if ($eval_formula_requested) {
							$filter['eval_formula'] = CConditionHelper::replaceNumericIds($formula, $formulaids);
						}
					}
					unset($filter);
				}
			}
			else {
				// In case no fields are actually selected in "filter", return empty array.
				foreach ($result as $correlation) {
					$filters[$correlation['correlationid']] = [];
				}
			}

			// Add filters to the result.
			foreach ($result as &$correlation) {
				$correlation['filter'] = $filters[$correlation['correlationid']];
			}
			unset($correlation);
		}

		// Adding operations.
		if ($options['selectOperations'] !== null && $options['selectOperations'] != API_OUTPUT_COUNT) {
			$operations = API::getApiService()->select('corr_operation', [
				'output' => $this->outputExtend($options['selectOperations'], [
					'correlationid', 'corr_operationid', 'type'
				]),
				'filter' => ['correlationid' => $correlationids],
				'preservekeys' => true
			]);
			$relation_map = $this->createRelationMap($operations, 'correlationid', 'corr_operationid');

			foreach ($operations as &$operation) {
				unset($operation['correlationid'], $operation['corr_operationid']);
			}
			unset($operation);

			$result = $relation_map->mapMany($result, $operations, 'operations');
		}

		return $result;
	}

	/**
	 * @static
	 *
	 * @param array $correlations
	 * @param array $db_correlations
	 */
	private static function addAffectedObjects(array $correlations, array &$db_correlations): void {
		$correlationids = [/* 'filter' => [], */ 'conditions' => [], 'operations' => []];

		foreach ($correlations as $correlation) {
			if (array_key_exists('filter', $correlation) && array_key_exists('conditions', $correlation['filter'])) {
				$correlationids['conditions'] = $correlation['correlationid'];
				$db_correlations[$correlation['correlationid']]['filter']['conditions'] = [];
			}

			if (array_key_exists('operations', $correlation)) {
				$correlationids['operations'] = $correlation['correlationid'];
				$db_correlations[$correlation['correlationid']]['operations'] = [];
			}
		}

		$options = [
			'output' => ['correlationid', 'evaltype', 'formula'],
			'filter' => ['correlationid' => array_column($correlations, 'correlationid')]
		];
		$db_filters = DBselect(DB::makeSql('correlation', $options));

		while ($db_filter = DBfetch($db_filters)) {
			$db_correlations[$db_filter['correlationid']]['filter'] =
				array_diff_key($db_filter, array_flip(['correlationid']));
		}

		if ($correlationids['conditions']) {
			$conditions = [];
			$sql = 'SELECT c.correlationid,c.corr_conditionid,c.type,ct.tag AS ct_tag,'.
					'cg.operator AS cg_operator,cg.groupid,ctp.oldtag,ctp.newtag,ctv.tag AS ctv_tag,'.
					'ctv.operator AS ctv_operator,ctv.value'.
				' FROM corr_condition c'.
				' LEFT JOIN corr_condition_tag ct ON ct.corr_conditionid = c.corr_conditionid'.
				' LEFT JOIN corr_condition_group cg ON cg.corr_conditionid = c.corr_conditionid'.
				' LEFT JOIN corr_condition_tagpair ctp ON ctp.corr_conditionid = c.corr_conditionid'.
				' LEFT JOIN corr_condition_tagvalue ctv ON ctv.corr_conditionid = c.corr_conditionid'.
				' WHERE '.dbConditionId('c.correlationid', [$correlationids['conditions']]);
			$db_conditions = DBselect($sql);

			while ($db_condition = DBfetch($db_conditions)) {
				$condition = [
					'corr_conditionid' => $db_condition['corr_conditionid'],
					'type' => $db_condition['type'],
					'formulaid' => ''
				];

				switch ($db_condition['type']) {
					case ZBX_CORR_CONDITION_OLD_EVENT_TAG:
					case ZBX_CORR_CONDITION_NEW_EVENT_TAG:
						$condition['tag'] = $db_condition['ct_tag'];
						$condition['operator'] = CONDITION_OPERATOR_EQUAL;
						break;

					case ZBX_CORR_CONDITION_NEW_EVENT_HOSTGROUP:
						$condition['groupid'] = $db_condition['groupid'];
						$condition['operator'] = $db_condition['cg_operator'];
						break;

					case ZBX_CORR_CONDITION_EVENT_TAG_PAIR:
						$condition['oldtag'] = $db_condition['oldtag'];
						$condition['newtag'] = $db_condition['newtag'];
						$condition['operator'] = CONDITION_OPERATOR_EQUAL;
						break;

					case ZBX_CORR_CONDITION_OLD_EVENT_TAG_VALUE:
					case ZBX_CORR_CONDITION_NEW_EVENT_TAG_VALUE:
						$condition['tag'] = $db_condition['ctv_tag'];
						$condition['value'] = $db_condition['value'];
						$condition['operator'] = $db_condition['ctv_operator'];
						break;
				}

				$conditions[$db_condition['correlationid']][$db_condition['corr_conditionid']] = $condition;
			}

			foreach ($conditions as $correlationid => $db_conditions) {
				if ($db_correlations[$correlationid]['filter']['evaltype'] == CONDITION_EVAL_TYPE_EXPRESSION) {
					$formula = $db_correlations[$correlationid]['filter']['formula'];

					$formulaids = CConditionHelper::getFormulaIds($formula);

					foreach ($db_conditions as &$db_condition) {
						$db_condition['formulaid'] = $formulaids[$db_condition['corr_conditionid']];
					}
					unset($db_condition);

					$db_correlations[$correlationid]['filter']['formula']
						= CConditionHelper::replaceNumericIds($formula, $formulaids);
				}

				$db_correlations[$correlationid]['filter']['conditions'] = $db_conditions;
			}
		}

		if ($correlationids['operations']) {
			$options = [
				'output' => ['corr_operationid', 'correlationid', 'type'],
				'filter' => ['correlationid' => $correlationids['operations']]
			];
			$db_operations = DBselect(DB::makeSql('corr_operation', $options));

			while ($db_operation = DBfetch($db_operations)) {
				$db_correlations[$db_operation['correlationid']]['operations'][$db_operation['corr_operationid']] =
				array_diff_key($db_operation, array_flip(['correlationid']));
			}
		}
	}
}
