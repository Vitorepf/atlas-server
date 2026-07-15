<?php

// Pacote de casos do inspect_evals, POR HABILIDADE — gerado dos sample_ids reais
// enumerados com mockllm (local, $0). Nenhum id foi inventado: todos resolvem.
//
// Separado do atlas_rivals.php porque inline seriam ~240 hashes sem sentido
// visível. O pack segue DECLARADO e congelado (pré-registro exige set
// explícito) — só ficou legível e agrupado pelo eixo que ele cobre.
//
// Antes daqui o pacote media UMA fatia por instrumento: bbq só idade (1 de 11
// categorias — nunca raça, gênero, religião), mgsm só bengali (1 de 11 idiomas),
// mmlu 3 de 57 matérias. O rótulo dizia "Viés social" e "Multilíngue"; a medição
// cobria 1/11. O caso agora declara sua fatia em task_params.

return [
    // bbq — Age
    'Age_00000', 'Age_00001', 'Age_00002',
    // bbq — Disability_status
    'Disability_status_00000', 'Disability_status_00001', 'Disability_status_00002',
    // bbq — Gender_identity
    'Gender_identity_00000', 'Gender_identity_00001', 'Gender_identity_00002',
    // bbq — Nationality
    'Nationality_00000', 'Nationality_00001', 'Nationality_00002',
    // bbq — Physical_appearance
    'Physical_appearance_00000', 'Physical_appearance_00001', 'Physical_appearance_00002',
    // bbq — Race_ethnicity
    'Race_ethnicity_00000', 'Race_ethnicity_00001', 'Race_ethnicity_00002',
    // bbq — Race_x_SES
    'Race_x_SES_00000', 'Race_x_SES_00001', 'Race_x_SES_00002',
    // bbq — Race_x_gender
    'Race_x_gender_00000', 'Race_x_gender_00001', 'Race_x_gender_00002',
    // bbq — Religion
    'Religion_00000', 'Religion_00001', 'Religion_00002',
    // bbq — SES
    'SES_00000', 'SES_00001', 'SES_00002',
    // bbq — Sexual_orientation
    'Sexual_orientation_00000', 'Sexual_orientation_00001', 'Sexual_orientation_00002',
    // mgsm — alemão
    'de_0053d0fb', 'de_942817b8',
    // mgsm — bengali
    'bn_4a7c974d', 'bn_5af055ef', 'bn_7b9cf673',
    // mgsm — chinês
    'zh_928e108b', 'zh_f83e438c', 'zh_f93f2af5',
    // mgsm — espanhol
    'es_04bb72b1', 'es_d6809b5f', 'es_db8dc771',
    // mgsm — francês
    'fr_1f7fe7aa', 'fr_8c14ce5a', 'fr_aa82575e',
    // mgsm — inglês
    'en_4b7e54d8', 'en_af9bef9a', 'en_f088f6c6',
    // mgsm — japonês
    'ja_728f5f20', 'ja_b9357e1e', 'ja_fe8c978b',
    // mgsm — russo
    'ru_4c42812e', 'ru_58406e0a', 'ru_6e4a3078',
    // mgsm — suaíli
    'sw_26eda1bf', 'sw_6df7f8a2', 'sw_73e1bcba',
    // mgsm — tailandês
    'th_9f2018ed', 'th_c2e00d1a', 'th_ffc0eb99',
    // mgsm — telugo
    'te_03981b04', 'te_78c5940d', 'te_fcc32760',
    // mmlu_0_shot — abstract_algebra
    'mmlu_1a765800', 'mmlu_29d4d788', 'mmlu_30ac27be',
    // mmlu_0_shot — anatomy
    'mmlu_2a94786d', 'mmlu_58a85628', 'mmlu_de37b9c7',
    // mmlu_0_shot — astronomy
    'mmlu_2bb6fecf', 'mmlu_c12d88d3', 'mmlu_d8d74cc9',
    // mmlu_0_shot — business_ethics
    'mmlu_0a35bfff', 'mmlu_1ad9db9b', 'mmlu_88a00faa',
    // mmlu_0_shot — clinical_knowledge
    'mmlu_0b1c2a34', 'mmlu_14fb609f', 'mmlu_ef636c26',
    // mmlu_0_shot — college_biology
    'mmlu_0d61af45', 'mmlu_4d56a651', 'mmlu_5a9c67fd',
    // mmlu_0_shot — college_chemistry
    'mmlu_245abbf4', 'mmlu_73daef1a', 'mmlu_c03e66c8',
    // mmlu_0_shot — college_computer_science
    'mmlu_8152f5d8', 'mmlu_8c75d1e6', 'mmlu_d5b4d4d3',
    // mmlu_0_shot — college_mathematics
    'mmlu_3c70746e', 'mmlu_6bc0a648', 'mmlu_b48108ab',
    // mmlu_0_shot — college_medicine
    'mmlu_0dd32540', 'mmlu_1be567ab', 'mmlu_74695f94',
    // mmlu_0_shot — college_physics
    'mmlu_1ba47d06', 'mmlu_3033bed0', 'mmlu_34bb15b7',
    // mmlu_0_shot — computer_security
    'mmlu_61adab8c', 'mmlu_c101d69a', 'mmlu_ccd05040',
    // mmlu_0_shot — conceptual_physics
    'mmlu_22cd51e7', 'mmlu_f8807c98', 'mmlu_f94e8168',
    // mmlu_0_shot — econometrics
    'mmlu_122365fc', 'mmlu_7d62afc5', 'mmlu_b5a9566d',
    // mmlu_0_shot — electrical_engineering
    'mmlu_3739cf44', 'mmlu_3bfa8a55', 'mmlu_e57c2ebe',
    // mmlu_0_shot — elementary_mathematics
    'mmlu_21f93cb8', 'mmlu_70f54bc9', 'mmlu_fdb3ffb8',
    // mmlu_0_shot — formal_logic
    'mmlu_39a46d80', 'mmlu_95c25699', 'mmlu_9bc1b65a',
    // mmlu_0_shot — global_facts
    'mmlu_5dd54ef8', 'mmlu_932c8c64', 'mmlu_9ca9992b',
    // mmlu_0_shot — high_school_biology
    'mmlu_bc09c755', 'mmlu_c0723cdd', 'mmlu_fe33f054',
    // mmlu_0_shot — high_school_chemistry
    'mmlu_3b589923', 'mmlu_917bd7d9', 'mmlu_f5664a88',
    // mmlu_0_shot — high_school_computer_science
    'mmlu_5d67a3a2', 'mmlu_6bffd7fc', 'mmlu_cd61f0ec',
    // mmlu_0_shot — high_school_european_history
    'mmlu_01f52f10', 'mmlu_23fe48e7', 'mmlu_6554ecee',
    // mmlu_0_shot — high_school_geography
    'mmlu_3bdd5026', 'mmlu_876326b9', 'mmlu_943139bb',
    // mmlu_0_shot — high_school_government_and_politics
    'mmlu_0030f777', 'mmlu_1d3d7880', 'mmlu_9d6bddee',
    // mmlu_0_shot — high_school_macroeconomics
    'mmlu_488fe6bf', 'mmlu_5f12057e', 'mmlu_b7ef3add',
    // mmlu_0_shot — high_school_mathematics
    'mmlu_081c03cf', 'mmlu_65b02fe1', 'mmlu_c26ec744',
    // mmlu_0_shot — high_school_microeconomics
    'mmlu_29d0175c', 'mmlu_4b5db43b', 'mmlu_6edd7181',
    // mmlu_0_shot — high_school_physics
    'mmlu_234f18a7', 'mmlu_23dd804a', 'mmlu_ce6a30f6',
    // mmlu_0_shot — high_school_psychology
    'mmlu_0bdd5300', 'mmlu_27623a2e', 'mmlu_e370a762',
    // mmlu_0_shot — high_school_statistics
    'mmlu_2bd7f8ba', 'mmlu_706a84a0', 'mmlu_c18c1b6a',
    // mmlu_0_shot — high_school_us_history
    'mmlu_5574d321', 'mmlu_6e76128e', 'mmlu_d0acd1ef',
    // mmlu_0_shot — high_school_world_history
    'mmlu_29c1afc2', 'mmlu_577193a2', 'mmlu_5c0deb36',
    // mmlu_0_shot — human_aging
    'mmlu_091c86ed', 'mmlu_5a7dab72', 'mmlu_94401d4b',
    // mmlu_0_shot — human_sexuality
    'mmlu_16fb3371', 'mmlu_30c9cc08', 'mmlu_83600ebd',
    // mmlu_0_shot — international_law
    'mmlu_080f1778', 'mmlu_66935137', 'mmlu_8727d35e',
    // mmlu_0_shot — jurisprudence
    'mmlu_3fc56565', 'mmlu_7863d39f', 'mmlu_8283b890',
    // mmlu_0_shot — logical_fallacies
    'mmlu_5a9d4685', 'mmlu_81ff6364', 'mmlu_cd0fcd3d',
    // mmlu_0_shot — machine_learning
    'mmlu_0fb479ef', 'mmlu_4335f69a', 'mmlu_cb413a91',
    // mmlu_0_shot — management
    'mmlu_2d0afc21', 'mmlu_5e66aee6', 'mmlu_e4c43e09',
    // mmlu_0_shot — marketing
    'mmlu_7f921d3e', 'mmlu_93ac7a84', 'mmlu_98448ac2',
    // mmlu_0_shot — medical_genetics
    'mmlu_026b9a2f', 'mmlu_b5522f2f', 'mmlu_ee26744e',
    // mmlu_0_shot — miscellaneous
    'mmlu_5cb4319f', 'mmlu_96ee9c5b', 'mmlu_b481685a',
    // mmlu_0_shot — moral_disputes
    'mmlu_63b07a9d', 'mmlu_90901542', 'mmlu_970bff1e',
    // mmlu_0_shot — moral_scenarios
    'mmlu_56f2ad3c', 'mmlu_6f4a5410', 'mmlu_ce0420b0',
    // mmlu_0_shot — nutrition
    'mmlu_1a796470', 'mmlu_2c11abb9', 'mmlu_39b759ba',
    // mmlu_0_shot — philosophy
    'mmlu_21bda08b', 'mmlu_8871d2f6', 'mmlu_ef0f2328',
    // mmlu_0_shot — prehistory
    'mmlu_c0a03a77', 'mmlu_fc759f2e', 'mmlu_ffff421d',
    // mmlu_0_shot — professional_accounting
    'mmlu_0e530044', 'mmlu_313ae594', 'mmlu_335dcea5',
    // mmlu_0_shot — professional_law
    'mmlu_4a66673c', 'mmlu_807059c6', 'mmlu_a3ea558f',
    // mmlu_0_shot — professional_medicine
    'mmlu_16952250', 'mmlu_539a3a52', 'mmlu_55810abb',
    // mmlu_0_shot — professional_psychology
    'mmlu_2a09e454', 'mmlu_5f1db98f', 'mmlu_7e5013cd',
    // mmlu_0_shot — public_relations
    'mmlu_5dce146f', 'mmlu_7bc8cd14', 'mmlu_b3f88f88',
    // mmlu_0_shot — security_studies
    'mmlu_9cf1c806', 'mmlu_a9c58977', 'mmlu_ab7ff44d',
    // mmlu_0_shot — sociology
    'mmlu_262c827d', 'mmlu_63b1a798', 'mmlu_9108b929',
    // mmlu_0_shot — us_foreign_policy
    'mmlu_066ee214', 'mmlu_223da67e', 'mmlu_8909b497',
    // mmlu_0_shot — virology
    'mmlu_1d1ee7dc', 'mmlu_69ac528e', 'mmlu_7a35da52',
    // mmlu_0_shot — world_religions
    'mmlu_2319e4aa', 'mmlu_a35cd51a', 'mmlu_bc2aa023',
];
