INSERT INTO llx_c_paiement (entity, code, libelle, type, active, module, position)
SELECT __ENTITY__, 'DVOUCH', 'Bon d''achat DoliVoucher', 2, 0, 'dolivoucher', 90
WHERE NOT EXISTS (SELECT 1 FROM llx_c_paiement WHERE entity=__ENTITY__ AND code='DVOUCH');

UPDATE llx_c_paiement SET active=0, module='dolivoucher'
WHERE entity=__ENTITY__ AND code='DVOUCH' AND module='dolivoucher';
