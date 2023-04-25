<?php

namespace WH1\PaygateLiqPay;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerInstallTrait;
	use StepRunnerUpgradeTrait;
	use StepRunnerUninstallTrait;

	public function installStep1()
	{
		$db = $this->db();

		$db->insert('xf_payment_provider', [
			'provider_id'    => "wh1LiqPay",
			'provider_class' => "WH1\\PaygateLiqPay:LiqPay",
			'addon_id'       => "WH1/PaygateLiqPay"
		]);
	}

	public function uninstallStep1()
	{
		$db = $this->db();

		$db->delete('xf_payment_profile', "provider_id = 'wh1LiqPay'");
		$db->delete('xf_payment_provider', "provider_id = 'wh1LiqPay'");
	}
}