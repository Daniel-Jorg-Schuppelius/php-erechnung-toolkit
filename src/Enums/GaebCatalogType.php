<?php
/*
 * Created on   : Sun Aug 16 2026
 * Author       : Daniel Jörg Schuppelius
 * Author Uri   : https://schuppelius.org
 * Filename     : GaebCatalogType.php
 * License      : AGPL-3.0-or-later
 * License Uri  : https://www.gnu.org/licenses/agpl-3.0.html
 */

declare(strict_types=1);

namespace ERechnungToolkit\Enums;

/**
 * Kind of catalogue a `Ctlg` declares (GAEB `tgCtlgEnum`).
 *
 * The edition is part of the type, not a separate field: a cost group "310"
 * means something different in DIN 276-1 2008-12 than in DIN 276 2018-12, which
 * changed more than 240 groups. The schema allows free strings besides these
 * values, so a reader must tolerate unknown ones.
 */
enum GaebCatalogType: string {
    case CostGroupDin276_1981 = 'cost group DIN 276-81';
    case CostGroupDin276_1993 = 'cost group DIN 276-93';
    case CostGroupDin276_2006 = 'cost group DIN 276-06';
    case CostGroupDin276_2008 = 'cost group DIN 276-1 2008-12';
    case CostGroupDin276_2018 = 'cost group DIN 276 2018-12';
    case Locality = 'locality';
    case WorkCategory = 'work category';
    case CostUnit = 'cost unit';
    case Bim = 'BIM';
    case Miscellaneous = 'miscellaneous';

    /** Is this one of the DIN 276 cost group catalogues? */
    public function isCostGroup(): bool {
        return str_starts_with($this->value, 'cost group');
    }
}
