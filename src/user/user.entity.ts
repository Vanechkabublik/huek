import {
  Column,
  CreateDateColumn,
  Entity,
  PrimaryGeneratedColumn,
  UpdateDateColumn,
} from 'typeorm';

// Роли пользователей. Добавляй сколько угодно — guard просто сравнивает строки.
// Пример: MODERATOR = 'moderator'. Таблица обновится сама (synchronize: true).
export enum Role {
  ADMIN = 'admin',
  USER = 'user',
}

@Entity('users')
export class User {
  @PrimaryGeneratedColumn('uuid')
  id: string;

  @Column()
  name: string;

  @Column({ unique: true })
  email: string;

  // select: false — пароль НЕ достаётся из БД обычными find*/findOne*,
  // поэтому случайно отдать хеш на фронт не получится.
  // Когда он реально нужен (логин) — запрашиваем явно через addSelect.
  @Column({ select: false })
  password: string;

  @Column({ type: 'enum', enum: Role, default: Role.USER })
  role: Role;

  @CreateDateColumn()
  createdAt: Date;

  @UpdateDateColumn()
  updatedAt: Date;
}
