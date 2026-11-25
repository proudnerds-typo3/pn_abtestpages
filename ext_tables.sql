CREATE TABLE pages (
    tx_abtestpages_b_id int(11) DEFAULT '0' NOT NULL,
    tx_abtestpages_b_percentage int(11) DEFAULT '50' NOT NULL,
    tx_abtestpages_cookie_time int(11) DEFAULT '86400' NOT NULL,

    KEY abtestpages_b_id (tx_abtestpages_b_id)
);