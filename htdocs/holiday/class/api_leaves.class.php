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

require_once DOL_DOCUMENT_ROOT.'/holiday/class/holiday.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';


/**
 * Read-only API for leave requests (module Holiday)
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Leaves extends DolibarrApi
{
	/**
	 * @var array<int,string>	Status codes, as Holiday::STATUS_*
	 */
	private static $STATUSCODES = array(
		Holiday::STATUS_DRAFT => 'draft',
		Holiday::STATUS_VALIDATED => 'validated',	// awaiting approval
		Holiday::STATUS_APPROVED => 'approved',
		Holiday::STATUS_CANCELED => 'canceled',
		Holiday::STATUS_REFUSED => 'refused',
	);

	/**
	 * @var array<string,string>	Allowed sort fields
	 */
	private static $SORTFIELDS = array(
		'rowid' => 'h.rowid',
		'ref' => 'h.ref',
		'fk_user' => 'h.fk_user',
		'date_start' => 'h.date_debut',
		'date_end' => 'h.date_fin',
		'status' => 'h.statut',
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
	 * List leave requests
	 *
	 * Read-only list of leave requests (HRM > Leave) with type, employee and approval data.
	 * Without the right "read all leave requests", only own requests and those of subordinates are returned.
	 * Status: 1 draft, 2 validated (awaiting approval), 3 approved, 4 canceled, 5 refused.
	 * halfday: 0 full days, -1 starts in the afternoon, 1 ends at noon, 2 both.
	 * With date_start or date_end, nb_open_day_in_period gives the working days of each leave inside that period.
	 *
	 * @param string	$date_start			Only leaves that end on or after this day (YYYY-MM-DD)	{@pattern /^(\d{4}-\d{2}-\d{2})?$/}
	 * @param string	$date_end			Only leaves that start on or before this day (YYYY-MM-DD)	{@pattern /^(\d{4}-\d{2}-\d{2})?$/}
	 * @param string	$user_ids			Employee ids. Example: '1' or '1,2,3'	{@pattern /^[0-9,]*$/}
	 * @param string	$type_codes			Leave type codes. Example: 'LEAVE_OTHER' or 'LEAVE_OTHER,LEAVE_SICK'	{@pattern /^[A-Za-z0-9_+\-,]*$/}
	 * @param string	$status				Statuses. Example: '3' or '2,3'	{@pattern /^[0-9,]*$/}
	 * @param string	$sortfield			Sort field: rowid, ref, fk_user, date_start, date_end, status
	 * @param string	$sortorder			Sort order: ASC or DESC
	 * @param int		$limit				Limit for list
	 * @param int		$page				Page number (starts from 0)
	 * @param string	$sqlfilters			Other criteria on h.* (llx_holiday) or t.* (llx_c_holiday_types). Example: "(h.description:like:'%sick%')"
	 * @param bool		$pagination_data	If true, the response is {data: [...], pagination: {total, page, page_count, limit}}
	 * @return array						Array of leave requests
	 *
	 * @throws RestException 400 Bad parameters
	 * @throws RestException 403 Access denied
	 * @throws RestException 503 Error when retrieving the list
	 */
	public function index($date_start = '', $date_end = '', $user_ids = '', $type_codes = '', $status = '', $sortfield = 'date_start', $sortorder = 'ASC', $limit = 100, $page = 0, $sqlfilters = '', $pagination_data = false)
	{
		$user = DolibarrApiAccess::$user;
		if (!$user->hasRight('holiday', 'read') && !$user->hasRight('holiday', 'readall')) {
			throw new RestException(403);
		}
		if ($date_start && !dol_stringtotime($date_start)) {
			throw new RestException(400, 'Bad value for date_start');
		}
		if ($date_end && !dol_stringtotime($date_end)) {
			throw new RestException(400, 'Bad value for date_end');
		}
		if (!isset(self::$SORTFIELDS[$sortfield])) {
			throw new RestException(400, 'Bad value for sortfield, use one of: '.implode(', ', array_keys(self::$SORTFIELDS)));
		}
		$sortorder = (strtoupper($sortorder) == 'DESC' ? 'DESC' : 'ASC');

		$sql = "SELECT h.rowid, h.ref, h.fk_user, h.fk_type, h.date_debut, h.date_fin, h.halfday, h.nb_open_day, h.statut,";
		$sql .= " h.fk_validator, h.date_valid, h.fk_user_valid, h.date_approval, h.fk_user_approve,";
		$sql .= " h.date_refuse, h.fk_user_refuse, h.detail_refuse, h.date_cancel, h.fk_user_cancel, h.description, h.date_create, h.tms,";
		$sql .= " t.code as type_code, t.label as type_label,";
		$sql .= " u.login as user_login, u.firstname as user_firstname, u.lastname as user_lastname, u.email as user_email, u.statut as user_status";
		$sql .= " FROM ".MAIN_DB_PREFIX."holiday as h";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_holiday_types as t ON t.rowid = h.fk_type";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = h.fk_user";
		$sql .= " WHERE h.entity IN (".getEntity('holiday').")";
		if (!$user->hasRight('holiday', 'readall')) {
			$childids = $user->getAllChildIds(1);
			$sql .= " AND h.fk_user IN (".$this->db->sanitize(implode(',', $childids)).")";
		}
		if ($date_start) {
			$sql .= " AND h.date_fin >= '".$this->db->escape($date_start)."'";
		}
		if ($date_end) {
			$sql .= " AND h.date_debut <= '".$this->db->escape($date_end)."'";
		}
		if ($user_ids) {
			$sql .= " AND h.fk_user IN (".$this->db->sanitize($user_ids).")";
		}
		if ($type_codes) {
			$codes = array();
			foreach (explode(',', $type_codes) as $code) {
				if ($code !== '') {
					$codes[] = "'".$this->db->escape($code)."'";
				}
			}
			$sql .= " AND t.code IN (".$this->db->sanitize(implode(',', $codes), 1).")";
		}
		if ($status) {
			$sql .= " AND h.statut IN (".$this->db->sanitize($status).")";
		}
		if ($sqlfilters) {
			$errormessage = '';
			$sql .= forgeSQLFromUniversalSearchCriteria($sqlfilters, $errormessage);
			if ($errormessage) {
				throw new RestException(400, 'Error when validating parameter sqlfilters -> '.$errormessage);
			}
		}

		$sqlTotals = preg_replace('/^SELECT .*? FROM /s', 'SELECT count(h.rowid) as total FROM ', $sql);

		$sql .= $this->db->order(self::$SORTFIELDS[$sortfield].",h.rowid", $sortorder.",".$sortorder);
		if ($limit) {
			if ($page < 0) {
				$page = 0;
			}
			$sql .= $this->db->plimit($limit, $limit * $page);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(503, 'Error when retrieving leave list: '.$this->db->lasterror());
		}

		$obj_ret = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$obj_ret[] = $this->_leaveFromRow($obj, $date_start, $date_end);
		}
		$this->db->free($resql);

		if ($pagination_data) {
			$resqltotal = $this->db->query($sqlTotals);
			$total = $resqltotal ? (int) $this->db->fetch_object($resqltotal)->total : 0;
			$obj_ret = array(
				'data' => $obj_ret,
				'pagination' => array(
					'total' => $total,
					'page' => $page,
					'page_count' => $limit ? ceil($total / $limit) : 1,
					'limit' => $limit,
				),
			);
		}

		return $obj_ret;
	}

	/**
	 * Build the API representation of one llx_holiday row
	 *
	 * Days are returned as 'YYYY-MM-DD' strings and datetimes as ISO 8601, so the client never has to
	 * guess the timezone of a timestamp. Missing values are null.
	 *
	 * @param	object	$obj			Row from the list query
	 * @param	string	$period_start	Start of the requested period (YYYY-MM-DD) or ''
	 * @param	string	$period_end		End of the requested period (YYYY-MM-DD) or ''
	 * @return	array<string,mixed>
	 */
	private function _leaveFromRow($obj, $period_start = '', $period_end = '')
	{
		$dstart = $this->db->jdate($obj->date_debut, 1);
		$dend = $this->db->jdate($obj->date_fin, 1);

		$toid = function ($v) {
			return ((int) $v > 0 ? (int) $v : null);
		};
		$todatetime = function ($v) {
			$t = $this->db->jdate($v);
			return ($t ? dol_print_date($t, 'dayhourrfc', 'gmt') : null);
		};

		// Working days of the leave inside the requested period. A half day at a clipped end no longer applies.
		$inperiod = null;
		if ($period_start || $period_end) {
			$pstart = $period_start ? dol_stringtotime($period_start.' 00:00:00', 1) : $dstart;
			$pend = $period_end ? dol_stringtotime($period_end.' 00:00:00', 1) : $dend;
			$cstart = max($dstart, $pstart);
			$cend = min($dend, $pend);
			if ($cstart > $cend) {
				$inperiod = 0.0;
			} else {
				$afternoonstart = (in_array((int) $obj->halfday, array(-1, 2)) && $cstart == $dstart);
				$morningend = (in_array((int) $obj->halfday, array(1, 2)) && $cend == $dend);
				$chalfday = ($afternoonstart ? ($morningend ? 2 : -1) : ($morningend ? 1 : 0));
				$inperiod = (float) num_open_day($cstart, $cend, 0, 1, $chalfday);
			}
		}

		return array(
			'id' => (int) $obj->rowid,
			'ref' => $obj->ref,
			'fk_user' => (int) $obj->fk_user,
			'user' => array(
				'login' => $obj->user_login,
				'firstname' => $obj->user_firstname,
				'lastname' => $obj->user_lastname,
				'email' => $obj->user_email,
				'status' => ($obj->user_status === null ? null : (int) $obj->user_status),
			),
			'fk_type' => (int) $obj->fk_type,
			'type_code' => $obj->type_code,
			'type_label' => $obj->type_label,
			'date_start' => dol_print_date($dstart, '%Y-%m-%d', 'gmt'),
			'date_end' => dol_print_date($dend, '%Y-%m-%d', 'gmt'),
			'halfday' => (int) $obj->halfday,
			'nb_open_day' => ($obj->nb_open_day === null ? null : (float) $obj->nb_open_day),
			// nb_open_day is denormalized and not always set: recompute it like the leave card does
			'nb_open_day_computed' => (float) num_open_day($dstart, $dend, 0, 1, (int) $obj->halfday),
			// only when date_start or date_end is given
			'nb_open_day_in_period' => $inperiod,
			'status' => (int) $obj->statut,
			'status_code' => (isset(self::$STATUSCODES[(int) $obj->statut]) ? self::$STATUSCODES[(int) $obj->statut] : null),
			'fk_validator' => $toid($obj->fk_validator),
			'fk_user_valid' => $toid($obj->fk_user_valid),
			'date_valid' => $todatetime($obj->date_valid),
			'fk_user_approve' => $toid($obj->fk_user_approve),
			'date_approval' => $todatetime($obj->date_approval),
			'fk_user_refuse' => $toid($obj->fk_user_refuse),
			'date_refuse' => $todatetime($obj->date_refuse),
			'detail_refuse' => $obj->detail_refuse,
			'fk_user_cancel' => $toid($obj->fk_user_cancel),
			'date_cancel' => $todatetime($obj->date_cancel),
			'description' => $obj->description,
			'date_create' => $todatetime($obj->date_create),
			'date_modification' => $todatetime($obj->tms),
		);
	}
}
