<?php

namespace App\Jobs;

class RunCampaignJob extends CampaignJob
{


    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;
    public $timeout                 = 3600;

    protected $campaign;

    /**
     * Create a new job instance.
     *
     * @note: Parent constructors are not called implicitly if the child class defines a constructor.
     *        In order to run a parent constructor, a call to parent::__construct() within the child constructor is required.
     *
     * @return void
     */
    public function __construct($campaign)
    {
        $this->campaign = $campaign;
        parent::__construct();

        // This line must go after the constructor
        $this->linkJobToCampaign();
    }


    /**
     * Execute the job.
     *
     * @return void
     */
    public function linkJobToCampaign(): void
    {
        $systemJob       = $this->getSystemJob();
        $systemJob->data = $this->campaign->id;
        $systemJob->save();
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        // start campaign
        $this->campaign->run();
    }
}
