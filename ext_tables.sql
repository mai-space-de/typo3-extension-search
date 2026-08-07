#
# Table structure for table 'tx_maisearch_index_queue'
#
CREATE TABLE tx_maisearch_index_queue (
	uid int(11) NOT NULL auto_increment,
	table_name varchar(64) DEFAULT '' NOT NULL,
	record_uid int(11) DEFAULT '0' NOT NULL,
	operation varchar(16) DEFAULT 'index' NOT NULL,
	status varchar(16) DEFAULT 'pending' NOT NULL,
	error_message text,
	queued_date int(11) DEFAULT '0' NOT NULL,
	processed_date int(11) DEFAULT '0' NOT NULL,
	PRIMARY KEY (uid),
	INDEX status (status),
	INDEX table_record (table_name, record_uid)
);
