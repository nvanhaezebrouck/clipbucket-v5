<?php

/**
 * Clipbucket Payment Handler
 * Manages ClipBucket-specific payment logic (membership, transactions)
 * Separated from PayPal-specific logic
 */
class ClipbucketPayment
{
    private static ?ClipbucketPayment $instance = null;

    public static function getInstance(): ClipbucketPayment
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Handle successful payment for membership
     *
     * @param int $idUserMembership User membership ID
     * @param int $idPaypalTransaction PayPal transaction ID
     * @param array $membershipData Membership data (frequency, etc.)
     * @throws Exception
     */
    public function successPayment(int $idUserMembership, int $idPaypalTransaction, array $membershipData = []): void
    {
        // Get membership data if not provided
        if (empty($membershipData)) {
            $membershipData = Membership::getInstance()->getAllHistoMembershipForUser([
                'id_user_membership' => $idUserMembership
            ]);
            if (!empty($membershipData)) {
                $membershipData = $membershipData[0];
            }
        }

        // Update membership status
        Membership::getInstance()->updateHistoMembership([
            'id_user_membership' => $idUserMembership,
            'id_user_memberships_status' => 2, // completed
            'date_start' => date('Y-m-d'),
            'date_end' => $this->getNextDate($membershipData['frequency'] ?? 'monthly', date('Y-m-d'))
        ]);

        // Link transaction to membership
        $this->insertTransactionOnUserMembership($idUserMembership, $idPaypalTransaction);
    }

    /**
     * Calculate next payment date based on frequency
     *
     * @param string $frequency (daily, weekly, monthly, yearly)
     * @param string $date Current date (Y-m-d)
     * @return string Next date (Y-m-d)
     */
    public function getNextDate(string $frequency, string $date): string
    {
        $timestamp = strtotime($date);

        switch (strtolower($frequency)) {
            case 'weekly':
                $next = strtotime('+1 week', $timestamp);
                break;
            case 'monthly':
                $next = strtotime('+1 month', $timestamp);
                break;
            case 'yearly':
                $next = strtotime('+1 year', $timestamp);
                break;
            default:
                return date('Y-m-d', $timestamp);
        }

        return date('Y-m-d', $next);
    }

    /**
     * Link PayPal transaction to user membership
     *
     * @param int $idUserMembership
     * @param int $idPaypalTransaction
     * @throws Exception
     */
    protected function insertTransactionOnUserMembership(int $idUserMembership, int $idPaypalTransaction): void
    {
        $sql = 'INSERT IGNORE INTO ' . cb_sql_table('user_memberships_transactions') . ' 
                (id_user_membership, id_paypal_transaction) 
                VALUES (' . mysql_clean($idUserMembership) . ', ' . mysql_clean($idPaypalTransaction) . ')';

        Clipbucket_db::getInstance()->execute($sql);
    }
}
