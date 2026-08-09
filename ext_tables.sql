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

#
# Table structure for table 'tx_maisearch_content_hash'
#
CREATE TABLE tx_maisearch_content_hash (
	uid int(11) NOT NULL auto_increment,
	document_type varchar(64) DEFAULT '' NOT NULL,
	document_uid int(11) DEFAULT '0' NOT NULL,
	content_hash varchar(64) DEFAULT '' NOT NULL,
	tstamp int(11) DEFAULT '0' NOT NULL,
	PRIMARY KEY (uid),
	UNIQUE KEY document (document_type, document_uid),
	INDEX content_hash (content_hash)
);

#
# Table structure for table 'tx_maisearch_chunks'
#
CREATE TABLE tx_maisearch_chunks (
	uid int(11) NOT NULL auto_increment,
	document_type varchar(64) DEFAULT '' NOT NULL,
	document_uid int(11) DEFAULT '0' NOT NULL,
	chunk_index int(11) DEFAULT '0' NOT NULL,
	chunk_content text,
	chunk_hash varchar(64) DEFAULT '' NOT NULL,
	tstamp int(11) DEFAULT '0' NOT NULL,
	PRIMARY KEY (uid),
	INDEX document (document_type, document_uid),
	INDEX chunk_index (document_type, document_uid, chunk_index),
	INDEX chunk_hash (chunk_hash)
);
