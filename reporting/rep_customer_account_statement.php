<?php
/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL,
	as published by the Free Software Foundation, either version 3
	of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/
$page_security = 'SA_CUSTSTATREP';
// ----------------------------------------------------------------
// $ Revision:	2.9 $
// Creator:	Maxime Bourget
// date_:	2013-09-06
// Title:	Customer Account Statement
// ----------------------------------------------------------------
$path_to_root="..";

include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/data_checks.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");
include_once($path_to_root . "/includes/db/crm_contacts_db.inc");

//----------------------------------------------------------------------------------------------------

print_statements();

//----------------------------------------------------------------------------------------------------

function overdueBreak($rep, $balance) {
}
function getTransactions($debtorno, $date, $show_also_allocated)
{
$date = '2013/03/08';
$start = '2000/01/01';
    $sql = "SELECT ".TB_PREF."debtor_trans.*,
				(".TB_PREF."debtor_trans.ov_amount + ".TB_PREF."debtor_trans.ov_gst + ".TB_PREF."debtor_trans.ov_freight +
				".TB_PREF."debtor_trans.ov_freight_tax + ".TB_PREF."debtor_trans.ov_discount)*if(".TB_PREF."debtor_trans.type in (".ST_SALESINVOICE."), 1, -1)
				AS TotalAmount, ".TB_PREF."debtor_trans.alloc AS Allocated,
				((".TB_PREF."debtor_trans.type != ".ST_SALESINVOICE.")
				OR ".TB_PREF."debtor_trans.due_date < '$date') AS OverDue,
				IF(due_date = '0000-00-00' , tran_date, due_date) AS EffectiveDate
				FROM ".TB_PREF."debtor_trans
				WHERE ".TB_PREF."debtor_trans.tran_date >= '$start' AND ".TB_PREF."debtor_trans.debtor_no = ".db_escape($debtorno)."
    				AND ".TB_PREF."debtor_trans.type <> ".ST_CUSTDELIVERY."
					AND ABS(".TB_PREF."debtor_trans.ov_amount + ".TB_PREF."debtor_trans.ov_gst + ".TB_PREF."debtor_trans.ov_freight +
				".TB_PREF."debtor_trans.ov_freight_tax + ".TB_PREF."debtor_trans.ov_discount) > 1e-6";
	$sql .= " ORDER BY IF(due_date = '0000-00-00' , tran_date, due_date)";

    return db_query($sql,"No transactions were returned");
}

//----------------------------------------------------------------------------------------------------

function print_statements()
{
	global $path_to_root, $systypes_array;

	include_once($path_to_root . "/reporting/includes/pdf_report.inc");

	$customer = $_POST['PARAM_0'];
	$currency = $_POST['PARAM_1'];
	$show_also_allocated = $_POST['PARAM_2'];
	$email = $_POST['PARAM_3'];
	$comments = $_POST['PARAM_4'];
	$orientation = $_POST['PARAM_5'];

	$orientation = ($orientation ? 'L' : 'P');
	$dec = user_price_dec();

	$cols = array(4, 64, 180,	250, 320, 385, 450, 515);

	//$headers in doctext.inc

	$aligns = array('left',	'left',	'left',	'left',	'right', 'right', 'right', 'right');

	$params = array('comments' => $comments);

	$cur = get_company_pref('curr_default');
	$PastDueDays1 = get_company_pref('past_due_days');
	$PastDueDays2 = 2 * $PastDueDays1;

	if ($email == 0)
		$rep = new FrontReport(_('STATEMENT'), "StatementBulk", user_pagesize(), 9, $orientation);
   if ($orientation == 'L')
    	recalculate_cols($cols);
 
	$sql = "SELECT debtor_no, name AS DebtorName, address, tax_id, curr_code, curdate() AS tran_date FROM ".TB_PREF."debtors_master";
	if ($customer != ALL_TEXT)
		$sql .= " WHERE debtor_no = ".db_escape($customer);
	else
		$sql .= " ORDER by name";
	$result = db_query($sql, "The customers could not be retrieved");

	while ($debtor_row=db_fetch($result))
	{
		$date = date('Y-m-d');

		$debtor_row['order_'] = "";

		$TransResult = getTransactions($debtor_row['debtor_no'], $date, $show_also_allocated);
		$baccount = get_default_bank_account($debtor_row['curr_code']);
		$params['bankaccount'] = $baccount['id'];
		if (db_num_rows($TransResult) == 0)
			continue;
		if ($email == 1)
		{
			$rep = new FrontReport("", "", user_pagesize(), 9, $orientation);
			$rep->title = _('STATEMENT');
			$rep->filename = "Statement" . $debtor_row['debtor_no'] . ".pdf";
			$rep->Info($params, $cols, null, $aligns);
		}

		$rep->filename = "MAE-ST-" . strtr($debtor_row['DebtorName'], " '", "__") ."--" . strtr(Today(), "/", "-") . ".pdf";
		$contacts = get_customer_contacts($debtor_row['debtor_no'], 'invoice');
		$rep->SetHeaderType('Header2');
		$rep->currency = $cur;
		$rep->Font();
		$rep->Info($params, $cols, null, $aligns);

		//= get_branch_contacts($branch['branch_code'], 'invoice', $branch['debtor_no']);
		$rep->SetCommonData($debtor_row, null, null, $baccount, ST_STATEMENT, $contacts);
		$rep->NewPage();
		$doctype = ST_STATEMENT;
/*
		$rep->NewLine();
		$rep->fontSize += 2;
		$rep->TextCol(0, 7, _("Overdue"));
		$rep->fontSize -= 2;
		$rep->NewLine(2);
*/

			$current = false;
		$balance = 0;
		$overdue = 0;
		while ($transaction_row=db_fetch($TransResult))
		{
			if(!$current && !$transaction_row['OverDue']==true) {
		$rep->fontSize += 2;
				$rep->NewLine(2);
		$rep->TextCol(0, 7, _("Coming up"));
		$rep->fontSize -= 2;
				$current = true;
				$overdue = $balance;
				$rep->NewLine(2);
			}

		if($current)
				$rep->SetTextColor(0, 0, 190);


			$DisplayTotal = number_format2(Abs($transaction_row["TotalAmount"]),$dec);
			$DisplayAlloc = number_format2($transaction_row["Allocated"],$dec);
			$DisplayNet = number_format2($transaction_row["TotalAmount"] - $transaction_row["Allocated"],$dec);

			$balance +=  $transaction_row["TotalAmount"];

			$rep->TextCol(1, 1, $systypes_array[$transaction_row['type']], -2);
			$rep->TextCol(2, 2,	$transaction_row['reference'], -2);
			$rep->TextCol(0, 3,	sql2date($transaction_row['EffectiveDate']), -2);
			if ($transaction_row['type'] == ST_SALESINVOICE)
				$rep->TextCol(3, 4,	sql2date($transaction_row['due_date']), -2);
			if ($transaction_row['type'] == ST_SALESINVOICE || $transaction_row['type'] == ST_BANKPAYMENT)
				$rep->TextCol(4, 5,	$DisplayTotal, -2);
			else
				$rep->TextCol(5, 6,	$DisplayTotal, -2);
		  $rep->TextCol(6, 7,	number_format2(-$balance, $dec), -2);

			$rep->NewLine();
			if ($rep->row < $rep->bottomMargin + (10 * $rep->lineHeight))
				$rep->NewPage();
		}
		if ($overdue > 0) {
			if ($rep->row < $rep->bottomMargin + (10 * $rep->lineHeight))
				$rep->NewPage();
			else
				$rep->NewLIne(2);
			$rep->fontSize += 2;
			$rep->SetTextColor(0, 0, 0);
			$rep->TextCol(1,3, 'Overdue Amount:');
			$rep->TextCol(3,4, number_format2($overdue, $dec)) ;
			$rep->NewLine(2);
			$rep->fontSize += 2;
			$rep->SetTextColor(190, 0, 0);
			$rep->TextCol(2,5, 'Please paye ASAP');
			$rep->fontSize -= 4;
			$rep->SetTextColor(0, 0, 0);
		$rep->NewLine();
		}

		$nowdue = "1-" . $PastDueDays1 . " " . _("Days");
		$pastdue1 = $PastDueDays1 + 1 . "-" . $PastDueDays2 . " " . _("Days");
		$pastdue2 = _("Over") . " " . $PastDueDays2 . " " . _("Days");
		$CustomerRecord = get_customer_details($debtor_row['debtor_no'], null, $show_also_allocated);
		$str = array(_("Current"), $nowdue, $pastdue1, $pastdue2, _("Total Balance"));
		$str2 = array(number_format2(($CustomerRecord["Balance"] - $CustomerRecord["Due"]),$dec),
			number_format2(($CustomerRecord["Due"]-$CustomerRecord["Overdue1"]),$dec),
			number_format2(($CustomerRecord["Overdue1"]-$CustomerRecord["Overdue2"]) ,$dec),
			number_format2($CustomerRecord["Overdue2"],$dec),
			number_format2($CustomerRecord["Balance"],$dec));
		$col = array($rep->cols[0], $rep->cols[0] + 110, $rep->cols[0] + 210, $rep->cols[0] + 310,
			$rep->cols[0] + 410, $rep->cols[0] + 510);
		$rep->row = $rep->bottomMargin + (10 * $rep->lineHeight - 6);
		for ($i = 0; $i < 5; $i++)
			$rep->TextWrap($col[$i], $rep->row, $col[$i + 1] - $col[$i], $str[$i], 'right');
		$rep->NewLine();
		for ($i = 0; $i < 5; $i++)
			$rep->TextWrap($col[$i], $rep->row, $col[$i + 1] - $col[$i], $str2[$i], 'right');
		if ($email == 1)
			$rep->End($email, _("Statement") . " " . _("as of") . " " . sql2date($date));

	}
	if ($email == 0)
		$rep->End();
}

?>
