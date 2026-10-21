<?php
/* Copyright (C) 2026   Join.To.IT
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';


/**
 * Read-only API with the payroll fields of users (same fields as the export profile "Pivot Salary")
 *
 * Only the columns listed here leave Dolibarr: no password, API key, birth date, address, personal e-mail,
 * tax id or bank data, whatever extrafields exist.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Payrollusers extends DolibarrApi
{
	/**
	 * @var string[]	User extrafields returned (if they exist in this database)
	 */
	private static $EXTRAFIELDS = array('fullnameukrainian', 'work_format', 'typeposition');

	/**
	 * @var array<string,string>	Allowed sort fields
	 */
	private static $SORTFIELDS = array(
		'rowid' => 't.rowid',
		'lastname' => 't.lastname',
		'login' => 't.login',
		'dateemployment' => 't.dateemployment',
		'dateemploymentend' => 't.dateemploymentend',
	);

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;

		$this->db = $db;
	}

	/**
	 * List users with payroll fields
	 *
	 * Monthly salary, employment start and end, job, employee flag, work format, type position and Ukrainian full name.
	 * salary is null when the caller may not read salaries (salary_visible: false).
	 * With active_on or period_from/period_to, users are selected by their employment dates whatever their status;
	 * otherwise only enabled users unless include_inactive. A missing start or end date does not exclude a user.
	 *
	 * @param string	$active_on			Only users employed on this day (YYYY-MM-DD)	{@pattern /^(\d{4}-\d{2}-\d{2})?$/}
	 * @param string	$period_from		Only users whose employment overlaps the period starting this day (YYYY-MM-DD)	{@pattern /^(\d{4}-\d{2}-\d{2})?$/}
	 * @param string	$period_to			Only users whose employment overlaps the period ending this day (YYYY-MM-DD)	{@pattern /^(\d{4}-\d{2}-\d{2})?$/}
	 * @param string	$user_ids			User ids. Example: '1' or '1,2,3'	{@pattern /^[0-9,]*$/}
	 * @param string	$type_position		Codes of the extrafield Type position. Example: '1' or '1,2'	{@pattern /^[0-9,]*$/}
	 * @param string	$employee			'1' only employees, '0' only non-employees, '' all	{@pattern /^[01]?$/}
	 * @param bool		$include_inactive	Without date filters, also return disabled users
	 * @param string	$sortfield			Sort field: rowid, lastname, login, dateemployment, dateemploymentend
	 * @param string	$sortorder			Sort order: ASC or DESC
	 * @param int		$limit				Limit for list
	 * @param int		$page				Page number (starts from 0)
	 * @return array						Array of users
	 *
	 * @throws RestException 400 Bad parameters
	 * @throws RestException 403 Access denied
	 * @throws RestException 503 Error when retrieving the list
	 */
	public function index($active_on = '', $period_from = '', $period_to = '', $user_ids = '', $type_position = '', $employee = '', $include_inactive = false, $sortfield = 'lastname', $sortorder = 'ASC', $limit = 500, $page = 0)
	{
		global $conf;

		$user = DolibarrApiAccess::$user;
		if (!$user->hasRight('user', 'user', 'lire') && empty($user->admin)) {
			throw new RestException(403, 'You are not allowed to read list of users');
		}
		foreach (array('active_on' => $active_on, 'period_from' => $period_from, 'period_to' => $period_to) as $key => $val) {
			if ($val && !dol_stringtotime($val)) {
				throw new RestException(400, 'Bad value for '.$key);
			}
		}
		if ($period_from && $period_to && $period_from > $period_to) {
			throw new RestException(400, 'period_from is after period_to');
		}
		if (!isset(self::$SORTFIELDS[$sortfield])) {
			throw new RestException(400, 'Bad value for sortfield, use one of: '.implode(', ', array_keys(self::$SORTFIELDS)));
		}
		$sortorder = (strtoupper($sortorder) == 'DESC' ? 'DESC' : 'ASC');
		// Same rule as the users API
		$salaryvisible = ((isModEnabled('salaries') && $user->hasRight('salaries', 'read')) || !isModEnabled('salaries'));

		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('user');
		$extracols = array();
		foreach (self::$EXTRAFIELDS as $key) {
			if (!empty($extrafields->attributes['user']['label'][$key])) {
				$extracols[] = $key;
			}
		}
		if ($type_position && !in_array('typeposition', $extracols)) {
			throw new RestException(400, 'Extrafield typeposition does not exist');
		}

		$sql = "SELECT t.rowid, t.login, t.email, t.lastname, t.firstname, t.job, t.employee, t.salary, t.dateemployment, t.dateemploymentend, t.statut, t.tms";
		foreach ($extracols as $key) {
			$sql .= ", ef.".$key;
		}
		$sql .= " FROM ".MAIN_DB_PREFIX."user as t";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user_extrafields as ef ON ef.fk_object = t.rowid";
		$sql .= " WHERE t.entity IN (".getEntity('user').")";
		if ($active_on) {
			$sql .= " AND (t.dateemployment IS NULL OR t.dateemployment <= '".$this->db->escape($active_on)."')";
			$sql .= " AND (t.dateemploymentend IS NULL OR t.dateemploymentend >= '".$this->db->escape($active_on)."')";
		}
		if ($period_to) {
			$sql .= " AND (t.dateemployment IS NULL OR t.dateemployment <= '".$this->db->escape($period_to)."')";
		}
		if ($period_from) {
			$sql .= " AND (t.dateemploymentend IS NULL OR t.dateemploymentend >= '".$this->db->escape($period_from)."')";
		}
		if (!$active_on && !$period_from && !$period_to && !$include_inactive) {
			$sql .= " AND t.statut = 1";
		}
		if ($user_ids) {
			$sql .= " AND t.rowid IN (".$this->db->sanitize($user_ids).")";
		}
		if ($type_position) {
			$sql .= " AND ef.typeposition IN (".$this->db->sanitize(implode(',', array_map(function ($c) {
				return "'".((int) $c)."'";
			}, array_filter(explode(',', $type_position), 'strlen'))), 1).")";
		}
		if ($employee !== '') {
			$sql .= " AND t.employee = ".((int) $employee);
		}
		$sql .= $this->db->order(self::$SORTFIELDS[$sortfield].",t.rowid", $sortorder.",".$sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$sql .= $this->db->plimit($limit, $limit * $page);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(503, 'Error when retrieving user list: '.$this->db->lasterror());
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$this->db->free($resql);

		$history = $salaryvisible ? $this->_salaryHistory(array_map(function ($o) {
			return (int) $o->rowid;
		}, $rows)) : array();

		$obj_ret = array();
		foreach ($rows as $obj) {
			$obj_ret[] = array(
				'id' => (int) $obj->rowid,
				'login' => $obj->login,
				'email' => $obj->email,
				'lastname' => $obj->lastname,
				'firstname' => $obj->firstname,
				'full_name_uk' => (in_array('fullnameukrainian', $extracols) ? $this->_strOrNull($obj->fullnameukrainian) : null),
				'job' => $this->_strOrNull($obj->job),
				'employee' => (bool) $obj->employee,
				'salary' => ($salaryvisible && $obj->salary !== null && $obj->salary !== '' ? (float) $obj->salary : null),
				'salary_visible' => $salaryvisible,
				'currency' => $conf->currency,
				'dateemployment' => $this->_day($obj->dateemployment),
				'dateemploymentend' => $this->_day($obj->dateemploymentend),
				'work_format' => (in_array('work_format', $extracols) ? $this->_option($extrafields, 'work_format', $obj->work_format) : null),
				'type_position' => (in_array('typeposition', $extracols) ? $this->_option($extrafields, 'typeposition', $obj->typeposition) : null),
				'status' => (int) $obj->statut,
				'salary_history' => ($salaryvisible ? (isset($history[(int) $obj->rowid]) ? $history[(int) $obj->rowid] : array()) : null),
				'date_modification' => $this->_datetime($obj->tms),
			);
		}

		return $obj_ret;
	}

	/**
	 * Salary changes from llx_user_employment, if HR records them there (empty in stock usage)
	 *
	 * @param	int[]	$ids	User ids
	 * @return	array<int,array<int,array<string,mixed>>>
	 */
	private function _salaryHistory($ids)
	{
		$ret = array();
		if (empty($ids)) {
			return $ret;
		}
		$sql = "SELECT fk_user, dateemployment, dateemploymentend, salary, job FROM ".MAIN_DB_PREFIX."user_employment";
		$sql .= " WHERE entity IN (".getEntity('user').") AND fk_user IN (".$this->db->sanitize(implode(',', $ids)).")";
		$sql .= " ORDER BY fk_user, dateemployment, rowid";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $ret;	// table missing or unreadable: no history rather than an error
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$ret[(int) $obj->fk_user][] = array(
				'date_from' => $this->_day($obj->dateemployment),
				'date_to' => $this->_day($obj->dateemploymentend),
				'salary' => ($obj->salary === null ? null : (float) $obj->salary),
				'job' => $this->_strOrNull($obj->job),
				'source' => 'user_employment',
			);
		}
		$this->db->free($resql);
		return $ret;
	}

	/**
	 * Code and label of a select extrafield
	 *
	 * @param	ExtraFields	$extrafields	Loaded extrafields
	 * @param	string		$key			Extrafield name
	 * @param	mixed		$code			Stored value
	 * @return	array{code:string,label:?string}|null
	 */
	private function _option($extrafields, $key, $code)
	{
		$code = $this->_strOrNull($code);
		if ($code === null || $code === '0') {
			return null;
		}
		$options = isset($extrafields->attributes['user']['param'][$key]['options']) ? $extrafields->attributes['user']['param'][$key]['options'] : array();
		return array('code' => $code, 'label' => (isset($options[$code]) ? trim($options[$code]) : null));
	}

	/**
	 * @param	mixed	$v	DB value
	 * @return	string|null
	 */
	private function _strOrNull($v)
	{
		if ($v === null) {
			return null;
		}
		$v = trim((string) $v);
		return ($v === '' ? null : $v);
	}

	/**
	 * @param	mixed	$v	DB date
	 * @return	string|null	YYYY-MM-DD
	 */
	private function _day($v)
	{
		$t = $this->db->jdate($v, 1);
		return ($t ? dol_print_date($t, '%Y-%m-%d', 'gmt') : null);
	}

	/**
	 * @param	mixed	$v	DB datetime
	 * @return	string|null	UTC RFC 3339
	 */
	private function _datetime($v)
	{
		$t = $this->db->jdate($v);
		return ($t ? dol_print_date($t, 'dayhourrfc', 'gmt') : null);
	}
}
