<?php

namespace App\Jobs;

class ReplicateContacts extends SystemJob
{


    protected $group_id;
    protected $contact;

    /**
     * Delete the job if its models no longer exist.
     *
     * @var bool
     */
    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param $group_id
     * @param $contact
     */
    public function __construct($group_id, $contact)
    {
        $this->group_id = $group_id;
        $this->contact  = $contact;
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
        $systemJob->data = $this->group_id;
        $systemJob->save();
    }


    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {

        foreach ($this->contact as $contact) {
            $new_contact             = $contact->replicate();
            $new_contact->uid        = uniqid();
            $new_contact->group_id   = $this->group_id;
            $new_contact->created_at = now()->toDateTimeString();
            $new_contact->updated_at = now()->toDateTimeString();

            $new_contact->save();
        }


    }
}
