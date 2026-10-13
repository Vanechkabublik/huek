// Логика авторизации: регистрация, логин, логаут.
//
// Схема простая — ОДИН access-токен (JWT), без refresh-токенов:
//   - регистрация только создаёт пользователя, токен НЕ выдаёт — дальше нужно залогиниться;
//   - при логине подписываем JWT с id пользователя;
//   - кладём его в httpOnly cookie (JS на фронте её не видит → не утащить через XSS),
//     браузер сам шлёт её с каждым запросом (на фронте нужен credentials: 'include');
//   - тот же токен возвращаем в ответе — на случай, если клиенту удобнее заголовок
//     Authorization: Bearer <token> (Postman, Swagger, мобилка);
//   - токен истёк → 401 → пользователь логинится заново.
//
// Проверка токена на запросах — не здесь, а в auth.guard.ts.

import {
  ConflictException,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { JwtService } from '@nestjs/jwt';
import { InjectRepository } from '@nestjs/typeorm';
import * as argon2 from 'argon2';
import type { Response } from 'express';
import { Repository } from 'typeorm';

import { Role, User } from '../user/user.entity';
import { LoginDto, RegisterDto } from './auth.dto';

// Имя cookie с токеном. Используется и тут, и в guard.
export const ACCESS_COOKIE = 'access_token';

// Что лежит внутри JWT. Только id: роль и прочее guard берёт из БД.
export interface JwtPayload {
  sub: string;
}

@Injectable()
export class AuthService {
  constructor(
    @InjectRepository(User) private readonly users: Repository<User>,
    private readonly jwt: JwtService,
    private readonly config: ConfigService,
  ) {}

  async register(dto: RegisterDto) {
    const email = dto.email.toLowerCase();

    if (await this.users.existsBy({ email })) {
      throw new ConflictException('Пользователь с таким email уже существует');
    }

    // Роль всегда USER — нельзя самому зарегаться админом.
    // Админа назначаем руками в БД: UPDATE users SET role = 'admin' WHERE email = '...';
    const user = await this.users.save(
      this.users.create({
        name: dto.name,
        email,
        password: await argon2.hash(dto.password),
        role: Role.USER,
      }),
    );

    // Токен не выдаём — только данные юзера (без хеша пароля).
    const { password: _password, ...safeUser } = user;
    return { user: safeUser };
  }

  async login(dto: LoginDto, res: Response) {
    // password помечен select: false в сущности, поэтому достаём его явно.
    const user = await this.users
      .createQueryBuilder('user')
      .addSelect('user.password')
      .where('user.email = :email', { email: dto.email.toLowerCase() })
      .getOne();

    // Одинаковая ошибка для "нет юзера" и "не тот пароль" —
    // чтобы нельзя было перебором узнать, какие email зарегистрированы.
    if (!user || !(await argon2.verify(user.password, dto.password))) {
      throw new UnauthorizedException('Неверный email или пароль');
    }

    const payload: JwtPayload = { sub: user.id };
    const accessToken = await this.jwt.signAsync(payload);

    // Время жизни cookie = времени жизни токена (exp — в секундах).
    const { exp } = this.jwt.decode<{ exp: number }>(accessToken);
    res.cookie(ACCESS_COOKIE, accessToken, {
      ...this.cookieOptions(),
      maxAge: exp * 1000 - Date.now(),
    });

    // Хеш пароля наружу не отдаём.
    const { password: _password, ...safeUser } = user;
    return { accessToken, user: safeUser };
  }

  logout(res: Response) {
    // JWT нельзя "отозвать" на сервере — просто удаляем cookie у клиента.
    res.clearCookie(ACCESS_COOKIE, this.cookieOptions());
    return { success: true };
  }

  private cookieOptions() {
    return {
      httpOnly: true,
      // В проде только по HTTPS. Локально (http://localhost) secure сломал бы cookie.
      secure: this.config.get('NODE_ENV') === 'production',
      // lax — cookie уходит при запросах с того же сайта и при переходах по ссылкам.
      // Если фронт и бэк на РАЗНЫХ доменах в проде — нужно 'none' (+ secure: true).
      sameSite: 'lax' as const,
      path: '/',
    };
  }
}
