INSERT IGNORE INTO product_types(id,name) VALUES(4,'เสื้อนักกีฬา');
INSERT IGNORE INTO zone_product_images(zone_id,product_type_id) SELECT id,4 FROM zones;
