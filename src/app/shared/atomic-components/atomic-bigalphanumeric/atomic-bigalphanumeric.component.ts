import { Input } from '@angular/core';
import { Component, OnInit } from '@angular/core';
import { BaseComponent } from '../../BaseComponent.class';

@Component({
  selector: 'app-atomic-bigalphanumeric',
  templateUrl: './atomic-bigalphanumeric.component.html',
  styleUrls: ['./atomic-bigalphanumeric.component.css'],
})
export class AtomicBigalphanumericComponent extends BaseComponent {
  @Input() property!: string | Array<string>;
  newItem!: string;

  isNewItemInputRequired() {
    if (this.exprIsTot) {
      if (this.property.length === 0) {
        return true;
      }
    }
    return false;
  }
}
