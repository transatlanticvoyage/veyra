<?php
/**
 * Boldcrypt niche source list — US home-service niches and their sub-niches.
 *
 * Loaded by Veyra::veyra_bc_dictionary(). Per niche:
 *   bases — core nouns. Each is ALSO combined with every prefix / suffix below
 *           ("termite" -> "termite control", "emergency termite treatment", …).
 *   terms — fixed phrases used exactly as written (no prefix/suffix expansion).
 *
 * Plurals are added automatically on the last word of every phrase
 * ("plumber" -> "plumbers", "roof repair" -> "roof repairs"), so list singulars.
 * Matching is case-insensitive, whole-word, and longest phrase wins, so
 * "termite control" bolds as one run instead of "termite" + plain "control".
 *
 * Add terms freely: an unused term costs nothing but a hash entry.
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(

    'prefixes' => array(
        'emergency', 'commercial', 'residential', '24 hour', '24-hour', '24/7', 'same day', 'same-day',
        'local', 'licensed', 'certified', 'professional', 'affordable', 'eco-friendly', 'organic', 'green',
        'home', 'house',
    ),

    'suffixes' => array(
        'repair', 'repairs', 'replacement', 'installation', 'install', 'installer', 'installers',
        'inspection', 'inspector', 'cleaning', 'cleaner', 'maintenance', 'tune-up', 'tune up',
        'service', 'services', 'company', 'companies', 'contractor', 'contractors', 'co',
        'expert', 'experts', 'specialist', 'specialists', 'pro', 'pros', 'professional', 'professionals',
        'technician', 'technicians', 'tech', 'techs', 'removal', 'treatment', 'treatments',
        'control', 'exterminator', 'exterminators', 'extermination', 'management', 'prevention',
        'restoration', 'remediation', 'cleanup', 'clean up', 'mitigation', 'solutions', 'estimate', 'estimates',
        'quote', 'quotes', 'cost', 'costs', 'business', 'crew', 'team', 'guys', 'man', 'men',
    ),

    'niches' => array(

        'pest_control' => array(
            'bases' => array(
                'pest', 'termite', 'rodent', 'rat', 'mouse', 'mice', 'bed bug', 'bedbug', 'cockroach', 'roach',
                'ant', 'carpenter ant', 'fire ant', 'mosquito', 'wasp', 'hornet', 'yellow jacket', 'bee', 'bees',
                'flea', 'tick', 'spider', 'scorpion', 'silverfish', 'earwig', 'cricket', 'stink bug', 'beetle',
                'flies', 'gnat', 'moth', 'centipede', 'millipede', 'mole', 'vole', 'gopher', 'snake',
                'wildlife', 'raccoon', 'squirrel', 'bat', 'opossum', 'possum', 'skunk', 'armadillo', 'bird',
                'pigeon', 'insect', 'bug', 'vermin', 'critter', 'animal', 'nuisance wildlife', 'wood boring beetle',
                'powderpost beetle', 'subterranean termite', 'drywood termite', 'formosan termite', 'lawn pest',
            ),
            'terms' => array(
                'pest control', 'exterminator', 'exterminators', 'exterminating', 'extermination',
                'fumigation', 'tenting', 'tent fumigation', 'termite tenting', 'termite bond', 'termite letter',
                'wdi inspection', 'wdo inspection', 'termite swarm', 'termite swarmers', 'rodent proofing',
                'rodent exclusion', 'wildlife exclusion', 'animal removal', 'animal control', 'bat exclusion',
                'mosquito misting', 'mosquito fogging', 'bed bug heat treatment', 'heat treatment',
                'integrated pest management', 'ipm', 'pest prevention', 'pest management', 'bug spray',
                'perimeter spray', 'pest inspection', 'rodent control', 'rat control', 'mouse control',
                'termite control', 'termite treatment', 'termite baiting', 'termite bait station',
            ),
        ),

        'roofing' => array(
            'bases' => array(
                'roof', 'roofing', 'roofer', 'shingle', 'metal roof', 'tile roof', 'flat roof', 'slate roof',
                'asphalt shingle', 'cedar shake', 'shake roof', 'tpo roof', 'epdm roof', 'rubber roof',
                'roof leak', 'roof coating', 'roof vent', 'skylight', 'flashing', 'soffit', 'fascia',
                'storm damage', 'hail damage', 'wind damage', 'roof deck', 'roof truss',
            ),
            'terms' => array(
                'roofing contractor', 'roofing company', 'roof repair', 'roof replacement', 'reroof', 're-roof',
                'reroofing', 'roof inspection', 'roof tarping', 'emergency roof tarp', 'new roof', 'roof restoration',
                'commercial roofing', 'residential roofing', 'storm restoration', 'insurance claim roof',
            ),
        ),

        'plumbing' => array(
            'bases' => array(
                'plumber', 'plumbing', 'drain', 'sewer', 'sewer line', 'water line', 'pipe', 'pipes', 'leak',
                'water leak', 'slab leak', 'leak detection', 'water heater', 'tankless water heater', 'hot water heater',
                'toilet', 'faucet', 'sink', 'garbage disposal', 'disposal', 'sump pump', 'well pump', 'water softener',
                'water filtration', 'water filter', 'backflow', 'backflow preventer', 'gas line', 'repipe', 'repiping',
                'shower', 'tub', 'bathtub', 'clog', 'clogged drain', 'main line', 'grease trap', 'lift station',
                'septic', 'septic tank', 'septic system', 'leach field', 'drain field', 'cesspool', 'boiler',
                'water main', 'frozen pipe', 'burst pipe', 'hydro jetting', 'hydrojetting', 'trenchless',
            ),
            'terms' => array(
                'drain cleaning', 'drain cleaner', 'drain clearing', 'drain unclogging', 'rooter', 'roto-rooter',
                'sewer camera inspection', 'camera inspection', 'video pipe inspection', 'sewer scope',
                'trenchless sewer repair', 'pipe bursting', 'pipe lining', 'cipp', 'septic pumping',
                'septic tank pumping', 'water heater installation', 'water heater repair', 'water heater replacement',
                'emergency plumber', 'emergency plumbing', 'plumbing contractor', 'plumbing company',
                'master plumber', 'leak repair',
            ),
        ),

        'hvac' => array(
            'bases' => array(
                'hvac', 'heating', 'cooling', 'air conditioning', 'air conditioner', 'ac', 'a/c', 'a.c.', 'air con',
                'furnace', 'heat pump', 'mini split', 'mini-split', 'ductless', 'ductless mini split', 'duct',
                'ductwork', 'air duct', 'dryer vent', 'thermostat', 'evaporator coil', 'condenser', 'compressor',
                'refrigerant', 'freon', 'air handler', 'geothermal', 'radiant heating', 'radiant floor heating',
                'heating and cooling', 'heating and air', 'heating & air', 'heating & cooling', 'air quality',
                'indoor air quality', 'iaq', 'air purifier', 'humidifier', 'dehumidifier', 'uv light', 'swamp cooler',
                'evaporative cooler', 'packaged unit', 'rooftop unit', 'rtu', 'chiller', 'baseboard heater',
            ),
            'terms' => array(
                'ac repair', 'a/c repair', 'ac installation', 'ac replacement', 'ac tune-up', 'ac service',
                'air conditioning repair', 'furnace repair', 'furnace installation', 'furnace replacement',
                'heat pump repair', 'hvac contractor', 'hvac company', 'hvac technician', 'hvac tech',
                'air duct cleaning', 'duct cleaning', 'dryer vent cleaning', 'hvac maintenance plan',
                'heating repair', 'cooling repair', 'refrigerant recharge', 'freon recharge',
            ),
        ),

        'electrical' => array(
            'bases' => array(
                'electrician', 'electrical', 'electric', 'wiring', 'rewiring', 'rewire', 'panel', 'electrical panel',
                'breaker', 'breaker box', 'circuit breaker', 'fuse box', 'outlet', 'gfci', 'gfci outlet', 
                'light fixture', 'lighting', 'landscape lighting', 'recessed lighting', 'ceiling fan', 'generator',
                'standby generator', 'whole house generator', 'whole home generator', 'ev charger', 'ev charging',
                'surge protector', 'whole house surge protector', 'meter base', 'service upgrade', 'panel upgrade',
                'knob and tube', 'aluminum wiring', 'smoke detector', 'low voltage', 'solar', 'solar panel',
                'solar panels', 'solar energy', 'battery backup', 'home battery',
            ),
            'terms' => array(
                'electrical contractor', 'electrical company', 'master electrician', 'licensed electrician',
                'emergency electrician', 'generator installation', 'generac', 'ev charger installation',
                'solar installation', 'solar installer', 'electrical inspection',
            ),
        ),

        'water_damage' => array(
            'bases' => array(
                'water damage', 'flood', 'flooding', 'flood damage', 'water extraction', 'water removal',
                'fire damage', 'smoke damage', 'soot', 'mold', 'black mold', 'mould', 'mildew', 'storm damage',
                'sewage', 'sewage backup', 'sewage cleanup', 'basement flooding', 'flooded basement', 'burst pipe',
                'structural drying', 'dehumidification', 'odor', 'biohazard', 'crime scene', 'hoarding',
                'asbestos', 'lead paint', 'radon',
            ),
            'terms' => array(
                'water damage restoration', 'water damage repair', 'water damage cleanup', 'water mitigation',
                'water restoration', 'flood cleanup', 'flood restoration', 'fire restoration', 'fire and water',
                'fire & water', 'smoke and fire', 'mold remediation', 'mold removal', 'mold inspection',
                'mold testing', 'mold abatement', 'asbestos abatement', 'asbestos removal', 'radon mitigation',
                'radon testing', 'restoration company', 'disaster restoration', 'disaster recovery',
                'emergency restoration', 'odor removal', 'biohazard cleanup',
            ),
        ),

        'landscaping' => array(
            'bases' => array(
                'landscaping', 'landscaper', 'landscape', 'lawn', 'lawn care', 'lawn mowing', 'mowing', 'yard',
                'yard work', 'grass', 'sod', 'turf', 'artificial turf', 'artificial grass', 'synthetic turf',
                'aeration', 'lawn aeration', 'overseeding', 'dethatching', 'fertilization', 'lawn fertilization',
                'weed control', 'irrigation', 'sprinkler', 'sprinkler system', 'drip irrigation', 'hardscape',
                'hardscaping', 'paver', 'pavers', 'patio', 'retaining wall', 'outdoor kitchen', 'fire pit',
                'mulch', 'mulching', 'garden', 'gardener', 'gardening', 'xeriscape', 'xeriscaping', 'leaf removal',
                'snow removal', 'snow plowing', 'snow plow', 'grading', 'drainage', 'french drain', 'yard drainage',
                'land clearing', 'brush clearing', 'hedge trimming', 'shrub trimming', 'bush trimming',
            ),
            'terms' => array(
                'lawn service', 'lawn care service', 'landscape design', 'landscape contractor',
                'landscaping company', 'landscape maintenance', 'grounds maintenance', 'commercial landscaping',
                'sprinkler repair', 'irrigation repair',
            ),
        ),

        'tree_service' => array(
            'bases' => array(
                'tree', 'tree service', 'tree removal', 'tree trimming', 'tree pruning', 'tree cutting',
                'stump', 'stump grinding', 'stump removal', 'arborist', 'certified arborist', 'tree care',
                'tree surgeon', 'tree surgery', 'limb removal', 'branch removal', 'crane tree removal',
                'storm cleanup', 'fallen tree', 'tree health', 'tree cabling', 'tree bracing', 'land clearing',
                'lot clearing', 'mulch delivery', 'firewood',
            ),
            'terms' => array(
                'emergency tree removal', 'tree company', 'tree experts', 'tree trimming service',
            ),
        ),

        'cleaning' => array(
            'bases' => array(
                'house cleaning', 'home cleaning', 'maid', 'maid service', 'maids', 'housekeeping', 'housekeeper',
                'janitorial', 'janitor', 'office cleaning', 'commercial cleaning', 'deep cleaning', 'deep clean',
                'move out cleaning', 'move-out cleaning', 'move in cleaning', 'move-in cleaning', 'post construction cleaning',
                'carpet', 'carpet cleaning', 'carpet cleaner', 'rug', 'rug cleaning', 'upholstery',
                'upholstery cleaning', 'tile and grout', 'tile & grout', 'grout cleaning', 'steam cleaning',
                'window cleaning', 'window washing', 'window washer', 'pressure washing', 'power washing',
                'pressure washer', 'power washer', 'soft washing', 'soft wash', 'house washing', 'roof cleaning',
                'gutter cleaning', 'driveway cleaning', 'concrete cleaning', 'deck cleaning', 'solar panel cleaning',
                'chimney sweep', 'chimney cleaning', 'duct cleaning', 'airbnb cleaning', 'vacation rental cleaning',
            ),
            'terms' => array(
                'cleaning service', 'cleaning services', 'cleaning company', 'cleaners', 'cleaning crew',
                'pressure washing service', 'exterior cleaning',
            ),
        ),

        'painting' => array(
            'bases' => array(
                'painter', 'painting', 'paint', 'house painting', 'house painter', 'interior painting',
                'exterior painting', 'cabinet painting', 'cabinet refinishing', 'deck staining', 'staining',
                'fence staining', 'drywall', 'drywall repair', 'sheetrock', 'popcorn ceiling removal',
                'popcorn ceiling', 'wallpaper', 'wallpaper removal', 'epoxy', 'epoxy flooring',
                'garage floor coating', 'stucco', 'stucco repair', 'plaster',
            ),
            'terms' => array(
                'painting contractor', 'painting company', 'commercial painting', 'residential painting',
                'painters', 'drywall contractor',
            ),
        ),

        'garage_door' => array(
            'bases' => array(
                'garage door', 'garage doors', 'garage door opener', 'overhead door', 'roll up door', 'roll-up door',
                'garage door spring', 'torsion spring', 'extension spring', 'garage door cable', 'garage door track',
                'garage door roller', 'garage door panel', 'liftmaster', 'chamberlain', 'genie opener', 'gate',
                'gate opener', 'automatic gate', 'driveway gate',
            ),
            'terms' => array(
                'garage door repair', 'garage door installation', 'garage door replacement',
                'garage door spring repair', 'broken spring', 'off track garage door',
            ),
        ),

        'pool' => array(
            'bases' => array(
                'pool', 'swimming pool', 'pool service', 'pool cleaning', 'pool maintenance', 'pool repair',
                'pool resurfacing', 'pool remodeling', 'pool builder', 'pool construction', 'pool pump',
                'pool heater', 'pool filter', 'pool equipment', 'pool liner', 'pool tile', 'pool deck', 'hot tub',
                'spa', 'jacuzzi', 'pool leak', 'pool leak detection', 'pool opening', 'pool closing', 'green pool',
                'pool fence', 'pool enclosure', 'screen enclosure', 'pool cage',
            ),
            'terms' => array(
                'pool company', 'pool contractor', 'pool guy', 'pool tech', 'pool care',
            ),
        ),

        'concrete_paving' => array(
            'bases' => array(
                'concrete', 'concrete contractor', 'concrete driveway', 'driveway', 'sidewalk', 'concrete patio',
                'concrete slab', 'slab', 'stamped concrete', 'decorative concrete', 'concrete repair',
                'concrete leveling', 'mudjacking', 'slabjacking', 'polyjacking', 'foam jacking', 'concrete lifting',
                'asphalt', 'paving', 'paver', 'blacktop', 'sealcoating', 'seal coating', 'parking lot',
                'parking lot striping', 'line striping', 'masonry', 'mason', 'brick', 'bricklayer', 'brick repair',
                'stone', 'stonework', 'stone mason', 'tuckpointing', 'tuck pointing', 'chimney repair', 'block wall',
                'cinder block', 'foundation', 'foundation repair', 'basement waterproofing', 'waterproofing',
                'crawl space', 'crawlspace', 'crawl space encapsulation', 'encapsulation', 'pier and beam',
                'underpinning', 'house leveling', 'slab repair', 'wall anchors', 'bowing wall', 'cracked foundation',
            ),
            'terms' => array(
                'concrete company', 'paving company', 'paving contractor', 'asphalt paving', 'driveway paving',
                'foundation repair company', 'foundation contractor', 'structural repair',
            ),
        ),

        'gutters_exterior' => array(
            'bases' => array(
                'gutter', 'gutters', 'seamless gutter', 'seamless gutters', 'gutter guard', 'gutter guards',
                'leaf guard', 'downspout', 'siding', 'vinyl siding', 'fiber cement siding', 'hardie board',
                'hardie siding', 'window', 'windows', 'replacement window', 'replacement windows', 'window replacement',
                'window installation', 'door', 'doors', 'entry door', 'front door', 'patio door', 'sliding door',
                'storm door', 'fence', 'fencing', 'wood fence', 'vinyl fence', 'chain link fence', 'privacy fence',
                'aluminum fence', 'iron fence', 'deck', 'decking', 'deck builder', 'composite deck', 'pergola',
                'gazebo', 'patio cover', 'awning', 'screen porch', 'sunroom', 'insulation', 'attic insulation',
                'spray foam', 'spray foam insulation', 'blown in insulation', 'attic', 'shutters', 'hurricane shutters',
                'impact windows', 'window tint', 'window tinting', 'glass', 'glass repair', 'shower door',
            ),
            'terms' => array(
                'gutter installation', 'gutter repair', 'gutter company', 'fence company', 'fence contractor',
                'fence installation', 'fence repair', 'deck repair', 'window company', 'siding contractor',
                'insulation contractor',
            ),
        ),

        'remodeling_handyman' => array(
            'bases' => array(
                'handyman', 'handymen', 'home repair', 'home improvement', 'remodel', 'remodeling', 'remodeler',
                'renovation', 'renovations', 'kitchen remodel', 'kitchen remodeling', 'bathroom remodel',
                'bathroom remodeling', 'bath remodel', 'basement finishing', 'basement remodel', 'home addition',
                'room addition', 'adu', 'general contractor', 'contractor', 'builder', 'home builder', 'custom home',
                'carpenter', 'carpentry', 'cabinet', 'cabinets', 'countertop', 'countertops', 'granite countertop',
                'quartz countertop', 'backsplash', 'tile', 'tile installation', 'tiling', 'flooring', 'floor',
                'hardwood', 'hardwood floor', 'hardwood flooring', 'floor refinishing', 'laminate', 'vinyl plank',
                'lvp', 'carpet installation', 'tile flooring', 'shower remodel', 'walk-in shower', 'walk in tub',
                'tub to shower', 'bathtub refinishing', 'bathtub reglazing', 'reglazing', 'closet', 'closets',
                'garage conversion', 'accessibility', 'wheelchair ramp', 'grab bar', 'grab bars',
            ),
            'terms' => array(
                'handyman service', 'handyman services', 'remodeling contractor', 'remodeling company',
                'flooring company', 'flooring contractor', 'flooring installer',
            ),
        ),

        'appliance_locksmith' => array(
            'bases' => array(
                'appliance', 'appliances', 'appliance repair', 'refrigerator', 'fridge', 'freezer', 'ice maker',
                'washer', 'dryer', 'washing machine', 'dishwasher', 'oven', 'stove', 'cooktop',
                'microwave', 'garbage disposal', 'locksmith', 'lock', 'locks', 'lockout', 'house lockout',
                'car lockout', 'rekey', 'rekeying', 'lock change', 'car key', 'key fob', 
                'deadbolt', 'smart lock', 'security system', 'alarm system', 'home security', 'security camera',
                'cctv', 'doorbell camera', 'intercom',
            ),
            'terms' => array(
                'appliance repair service', 'emergency locksmith', 'locksmith service', 'mobile locksmith',
                '24 hour locksmith',
            ),
        ),

        'moving_junk' => array(
            'bases' => array(
                'mover', 'movers', 'moving company', 'local movers', 'long distance movers', 'piano movers',
                'self storage', 'junk removal', 'junk hauling', 'junk', 'hauling',
                'trash removal', 'debris removal', 'dumpster', 'dumpster rental', 'roll off dumpster',
                'roll-off dumpster', 'demolition', 'estate cleanout', 'cleanout', 'clean out',
                'furniture removal', 'appliance removal', 'hot tub removal', 'shed removal', 'towing', 'tow truck',
                'wrecker', 'roadside assistance', 'jump start', 'flat tire', 'car lockout',
            ),
            'terms' => array(
                'moving services', 'junk removal service', 'towing service', 'towing company', '24 hour towing',
                'emergency towing',
            ),
        ),

        'chimney_fireplace' => array(
            'bases' => array(
                'chimney', 'chimney sweep', 'chimney cleaning', 'chimney repair', 'chimney inspection',
                'chimney cap', 'chimney liner', 'chimney crown', 'fireplace', 'fireplace repair',
                'fireplace installation', 'gas fireplace', 'wood stove', 'pellet stove', 'fireplace insert',
                'dryer vent cleaning', 'masonry chimney',
            ),
            'terms' => array(
                'chimney company', 'chimney service', 'chimney experts',
            ),
        ),
    ),
);
